<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;


use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Dependent;
use Modules\ClientMasterlist\App\Models\HealthInsurance;
use Modules\ClientMasterlist\App\Models\Enrollment;
use Modules\ClientMasterlist\App\Models\InsuranceProvider;
use App\Models\Company;

use App\Http\Traits\DateSanitizer;
use App\Http\Traits\UppercaseInput;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportEnrolleeController extends Controller
{
    use UppercaseInput, DateSanitizer;

    /**
     * Analyze and sanitize dates in bulk import data with improved format detection
     * 
     * @param array $importData Array of import rows
     * @param array $dateFields Array of date field names to process
     * @return array Analysis results and sanitized data
     */
    private function analyzeBulkDates(array $importData, array $dateFields)
    {
        // Collect all date values for analysis
        $allDates = [];
        foreach ($importData as $row) {
            foreach ($dateFields as $field) {
                if (!empty($row[$field])) {
                    $allDates[] = $row[$field];
                }
            }
        }

        // Analyze date formats
        $analysis = $this->analyzeDateFormats($allDates);

        // Log the aggressive enforcement decision
        if ($analysis['dmy_indicators'] > 0) {
            Log::info("AGGRESSIVE DD/MM FORMAT ENFORCEMENT ACTIVATED", [
                'total_dates_analyzed' => count($allDates),
                'dd_mm_indicators_found' => $analysis['dmy_indicators'],
                'ambiguous_dates_forced_to_dd_mm' => $analysis['ambiguous_dates'],
                'enforcement_reason' => 'Found DD/MM indicators - forcing ALL dates to DD/MM format'
            ]);
        }

        Log::info("Bulk date analysis completed", [
            'total_dates_analyzed' => count($allDates),
            'recommended_format' => $analysis['recommended_format'],
            'confidence' => $analysis['confidence'],
            'dmy_indicators' => $analysis['dmy_indicators'],
            'mdy_indicators' => $analysis['mdy_indicators'],
            'ambiguous_dates' => $analysis['ambiguous_dates']
        ]);

        return $analysis;
    }

    /**
     * Sanitize dates using detected format preference
     * 
     * @param mixed $date Date value to sanitize
     * @param string $detectedFormat Format preference from bulk analysis
     * @return string|null Sanitized date or null
     */
    private function sanitizeDateWithFormat($date, $detectedFormat)
    {
        if (empty($date)) return null;

        $sanitized = $this->sanitizeDate($date, $detectedFormat);

        if (!$sanitized) {
            Log::warning("Failed to sanitize date", [
                'original_date' => $date,
                'detected_format' => $detectedFormat
            ]);
        }

        return $sanitized;
    }

    /**
     * Normalize values for comparison to avoid unnecessary updates
     */
    private function normalizeValue($value)
    {
        // Handle null and empty values
        if (is_null($value) || $value === '' || $value === 'NULL' || $value === 'null') {
            return null;
        }

        // Convert to string and trim whitespace
        $normalized = trim((string) $value);

        // Handle empty string after trimming
        if ($normalized === '') {
            return null;
        }

        // Convert to uppercase for string comparison (since we use uppercaseStrings)
        if (is_string($value) && !is_numeric($normalized)) {
            $normalized = strtoupper($normalized);
        }

        // Handle numeric values
        if (is_numeric($normalized)) {
            // If it's a whole number, convert to int, otherwise float
            if ((float) $normalized == (int) $normalized) {
                return (int) $normalized;
            } else {
                return (float) $normalized;
            }
        }

        return $normalized;
    }

    public function import(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $enrollees = $request->input('enrollees', []);
            $enrollmentId = $request->input('enrollment_id');

            // Perform bulk date analysis before processing individual records
            $dateFields = [
                'birth_date',
                'employment_start_date',
                'employment_end_date',
            ];

            // Check if user specified a date format preference
            $forcedFormat = $request->input('date_format', 'mdy'); // 'dmy', 'mdy', or null for auto

            if ($forcedFormat && in_array($forcedFormat, ['dmy', 'mdy'])) {
                $dateAnalysis = ['recommended_format' => $forcedFormat];
                Log::info("Using forced date format", [
                    'total_enrollees' => count($enrollees),
                    'forced_date_format' => $forcedFormat
                ]);
            } else {
                $dateAnalysis = $this->analyzeBulkDates($enrollees, $dateFields);
                Log::info("Starting import with date format analysis", [
                    'total_enrollees' => count($enrollees),
                    'detected_date_format' => $dateAnalysis['recommended_format'],
                    'date_confidence' => $dateAnalysis['confidence']
                ]);
            }

            $principalMap = [];
            $dependentsByPrincipal = [];
            $insuranceFields = (new HealthInsurance())->getFillable();

            // Separate principals and dependents
            foreach ($enrollees as $index => $enrolleeData) {
                $relationRaw = $enrolleeData['relation'] ?? '';
                $relation = strtoupper(trim($relationRaw));
                $employeeId = $enrolleeData['employee_id'] ?? null;

                // Separate health insurance fields from enrollee data
                $healthInsuranceData = [];

                foreach ($insuranceFields as $field) {
                    if (array_key_exists($field, $enrolleeData)) {
                        $healthInsuranceData[$field] = $enrolleeData[$field];
                        unset($enrolleeData[$field]);
                    }
                }

                // Process health insurance data
                $isPrincipal = ($relation === 'PRINCIPAL' || $relation === 'EMPLOYEE');

                $healthInsuranceData = $this->processHealthInsuranceData($healthInsuranceData, $isPrincipal ? false : true);

                if (isset($enrolleeData['enrollment_status']) || !empty($enrolleeData['enrollment_status'])) {
                    $enrolleeData['enrollment_status'] = $enrolleeData['enrollment_status'];
                } else {
                    // Set enrollment status based on health insurance data
                    if (!empty($healthInsuranceData['certificate_number']) && (!$healthInsuranceData['is_renewal'])) {
                        $enrolleeData['enrollment_status'] = 'APPROVED';
                    } elseif (!empty($healthInsuranceData['certificate_number']) && $healthInsuranceData['is_renewal']) {
                        $enrolleeData['enrollment_status'] = 'FOR-RENEWAL';
                    } elseif ($healthInsuranceData['is_skipping'] || !empty($healthInsuranceData['reason_for_skipping'])) {
                        $enrolleeData['enrollment_status'] = 'SKIPPED';
                    } else {
                        // Default status when no specific condition is met
                        $enrolleeData['enrollment_status'] = 'PENDING';
                    }
                }

                if ($isPrincipal) {

                    $principal = $this->createOrUpdatePrincipalWithSoftDelete($enrolleeData, $enrollmentId, $employeeId, $dateAnalysis['recommended_format']);

                    // Verify principal was created/updated successfully
                    if (!$principal || !$principal->id) {
                        throw new \Exception("Failed to create or update principal for employee {$employeeId}");
                    }

                    $principalMap[$employeeId] = $principal;

                    // Attach health insurance if data exists
                    if (!empty($healthInsuranceData)) {
                        // Set coverage_end_date from employment_end_date if employee is INACTIVE
                        if (isset($enrolleeData['employment_end_date'])) {
                            $healthInsuranceData['coverage_end_date'] = $enrolleeData['employment_end_date'];
                        }

                        $this->attachHealthInsurance(new Request([
                            'enrollee_id' => $principal->id,
                            'insurance' => $healthInsuranceData
                        ]), $dateAnalysis['recommended_format']);
                    }
                } else {
                    if (!isset($dependentsByPrincipal[$employeeId])) {
                        $dependentsByPrincipal[$employeeId] = [];
                    }
                    $dependentsByPrincipal[$employeeId][] = [
                        'enrollee_data' => $enrolleeData,
                        'health_insurance_data' => $healthInsuranceData
                    ];
                }
            }

            // Process dependents
            foreach ($dependentsByPrincipal as $employeeId => $dependents) {
                $principal = $principalMap[$employeeId] ?? null;
                if (!$principal) {
                    Log::warning('Principal not found for dependents', ['employee_id' => $employeeId]);
                    continue;
                }

                // Double-check that the principal still exists in the database
                $principalExists = Enrollee::withTrashed()->find($principal->id);
                if (!$principalExists) {
                    Log::error('Principal exists in map but not in database', [
                        'employee_id' => $employeeId,
                        'principal_id' => $principal->id,
                        'principal_object' => $principal->toArray()
                    ]);
                    continue;
                }

                $dependentsData = [];
                foreach ($dependents as $dependent) {
                    $enrolleeData = $dependent['enrollee_data'];
                    $healthInsuranceData = $dependent['health_insurance_data'];

                    $enrolleeData['principal_id'] = $principal->id;
                    $enrolleeData['principal_employee_id'] = $principal->employee_id;

                    $dependentsData[] = $enrolleeData;
                }

                // Use attachDependents method
                $response = $this->attachDependents(new Request([
                    'enrollee_id' => $principal->id,
                    'dependents' => $dependentsData
                ]), $dateAnalysis['recommended_format']);

                // Attach health insurance for each dependent
                if ($response->getStatusCode() === 200) {
                    $responseData = json_decode($response->getContent(), true);
                    $createdDependents = $responseData['dependents'] ?? [];

                    foreach ($dependents as $index => $dependent) {
                        $healthInsuranceData = $dependent['health_insurance_data'];
                        if (!empty($healthInsuranceData) && isset($createdDependents[$index])) {
                            $this->attachHealthInsurance(new Request([
                                'dependent_id' => $createdDependents[$index]['id'],
                                'insurance' => $healthInsuranceData
                            ]), $dateAnalysis['recommended_format']);
                        }
                    }
                } else {
                    Log::error('Failed to attach dependents', [
                        'employee_id' => $employeeId,
                        'response_code' => $response->getStatusCode(),
                        'response_body' => $response->getContent()
                    ]);
                }
            }

            // Commit all transaction levels to ensure data is actually saved
            $transactionLevel = DB::transactionLevel();

            for ($i = 0; $i < $transactionLevel; $i++) {
                DB::commit();
            }

            return response()->json(['message' => 'Import successful'], 200);
        } catch (\Exception $e) {

            // Rollback all transaction levels
            $transactionLevel = DB::transactionLevel();
            for ($i = 0; $i < $transactionLevel; $i++) {
                DB::rollBack();
            }

            return response()->json(['message' => 'Import failed', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Find enrollment by company code and insurance provider title
     */
    private function findEnrollmentByCompanyAndProvider(string $companyCode, string $insuranceProviderTitle): int
    {
        // Find company by company_code
        $company = Company::where('company_code', strtoupper($companyCode))->first();

        if (!$company) {
            throw new \Exception('Company not found with code: ' . $companyCode);
        }

        // Find insurance provider by title
        $insuranceProvider = InsuranceProvider::where('title', strtoupper($insuranceProviderTitle))->first();

        if (!$insuranceProvider) {
            throw new \Exception('Insurance provider not found with title: ' . $insuranceProviderTitle);
        }

        // Find enrollment by company_id and insurance_provider_id
        $enrollment = Enrollment::where('company_id', $company->id)
            ->where('insurance_provider_id', $insuranceProvider->id)
            ->first();

        if (!$enrollment) {
            throw new \Exception('No enrollment found for company: ' . $companyCode . ' and insurance provider: ' . $insuranceProviderTitle);
        }

        return $enrollment->id;
    }

    /**
     * Import enrollees with company and provider info
     */
    public function importWithCompanyAndProvider(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $enrollees = $request->input('enrollees', []);

            // Perform bulk date analysis before processing individual records
            $dateFields = [
                'birth_date',
                'employment_start_date',
                'employment_end_date',
            ];

            // Check if user specified a date format preference
            $forcedFormat = $request->input('date_format', 'dmy'); // 'dmy', 'mdy', or null for auto

            if ($forcedFormat && in_array($forcedFormat, ['dmy', 'mdy'])) {
                $dateAnalysis = ['recommended_format' => $forcedFormat];
                Log::info('Using forced date format for import with company and provider', [
                    'enrollees_count' => count($enrollees),
                    'forced_date_format' => $forcedFormat,
                    'first_enrollee' => $enrollees[0] ?? null
                ]);
            } else {
                $dateAnalysis = $this->analyzeBulkDates($enrollees, $dateFields);
                Log::info('Import request data with date format analysis:', [
                    'enrollees_count' => count($enrollees),
                    'first_enrollee' => $enrollees[0] ?? null,
                    'detected_date_format' => $dateAnalysis['recommended_format'],
                    'date_confidence' => $dateAnalysis['confidence']
                ]);
            }

            $principalMap = [];
            $insuranceFields = (new HealthInsurance())->getFillable();

            foreach ($enrollees as $enrolleeData) {
                $employeeId = $enrolleeData['employee_id'] ?? null;
                $enrolleeCompanyCode = $enrolleeData['company_code'] ?? null;
                $enrolleeInsuranceProviderTitle = $enrolleeData['insurance_provider_title'] ?? null;

                // Validate required fields
                if (!$enrolleeCompanyCode || !$enrolleeInsuranceProviderTitle) {
                    Log::warning('Missing company/provider info for enrollee', [
                        'employee_id' => $employeeId,
                        'company_code' => $enrolleeCompanyCode,
                        'insurance_provider_title' => $enrolleeInsuranceProviderTitle
                    ]);
                    return response()->json(['message' => 'Missing company_code or insurance_provider_title for employee: ' . $employeeId], 400);
                }

                // Find enrollment by company and provider
                try {
                    $currentEnrollmentId = $this->findEnrollmentByCompanyAndProvider($enrolleeCompanyCode, $enrolleeInsuranceProviderTitle);
                } catch (\Exception $e) {
                    return response()->json(['message' => $e->getMessage()], 404);
                }

                // Remove company_code and insurance_provider_title from enrollee data
                unset($enrolleeData['company_code'], $enrolleeData['insurance_provider_title']);

                // Separate health insurance fields from enrollee data
                $healthInsuranceData = [];

                foreach ($insuranceFields as $field) {
                    if (array_key_exists($field, $enrolleeData)) {
                        $healthInsuranceData[$field] = $enrolleeData[$field];
                        unset($enrolleeData[$field]);
                    }
                }

                // Handle employment end date for health insurance
                if (isset($enrolleeData['employment_end_date']) && !empty($enrolleeData['employment_end_date'])) {
                    $healthInsuranceData['coverage_end_date'] = $enrolleeData['employment_end_date'];
                }

                // Process health insurance data
                $healthInsuranceData = $this->processHealthInsuranceData($healthInsuranceData, false);

                if (!$healthInsuranceData['is_renewal']) {

                    // Create or update principal with soft delete handling
                    $principal = $this->createOrUpdatePrincipalWithSoftDelete($enrolleeData, $currentEnrollmentId, $employeeId, $dateAnalysis['recommended_format']);
                    $principalMap[$employeeId] = $principal;

                    // Attach health insurance if data exists
                    if (!empty($healthInsuranceData)) {
                        $this->attachHealthInsurance(new Request([
                            'enrollee_id' => $principal->id,
                            'insurance' => $healthInsuranceData
                        ]), $dateAnalysis['recommended_format']);
                    }
                }
            }

            Log::info('About to commit transaction', [
                'principals_processed' => count($principalMap),
                'transaction_level_before_commit' => DB::transactionLevel()
            ]);

            // Commit all transaction levels to ensure data is actually saved
            $transactionLevel = DB::transactionLevel();

            for ($i = 0; $i < $transactionLevel; $i++) {
                DB::commit();

                Log::info('Committed transaction level', [
                    'level' => $i + 1,
                    'remaining_levels' => DB::transactionLevel()
                ]);
            }

            Log::info('All transactions committed successfully', [
                'principals_processed' => count($principalMap),
                'final_transaction_level' => DB::transactionLevel()
            ]);

            return response()->json(['message' => 'Import successful'], 200);
        } catch (\Exception $e) {

            Log::error('Exception occurred during importWithCompanyAndProvider', [
                'error' => $e->getMessage(),
                'transaction_level_before_rollback' => DB::transactionLevel()
            ]);

            // Rollback all transaction levels
            $transactionLevel = DB::transactionLevel();

            for ($i = 0; $i < $transactionLevel; $i++) {
                DB::rollBack();
                Log::info('Rolled back transaction level', [
                    'level' => $i + 1,
                    'remaining_levels' => DB::transactionLevel()
                ]);
            }

            Log::info('All transactions rolled back for importWithCompanyAndProvider', [
                'final_transaction_level' => DB::transactionLevel()
            ]);

            return response()->json(['message' => 'Import failed', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create or update principal with soft delete handling
     */
    private function createOrUpdatePrincipalWithSoftDelete(array $enrolleeData, int $enrollmentId, string $employeeId, string $dateFormat = 'auto'): Enrollee
    {
        $enrolleeData['enrollment_id'] = $enrollmentId;

        // Store original employment_end_date before sanitization to check for soft deletion
        $originalEmploymentEndDate = $enrolleeData['employment_end_date'] ?? null;

        // Sanitize date fields using detected format
        $enrolleeDateFields = ['birth_date', 'employment_start_date', 'employment_end_date'];

        foreach ($enrolleeDateFields as $dateField) {
            if (isset($enrolleeData[$dateField]) && !empty($enrolleeData[$dateField])) {
                $originalDate = $enrolleeData[$dateField];
                $enrolleeData[$dateField] = $this->sanitizeDateWithFormat($enrolleeData[$dateField], $dateFormat);
                Log::info("Sanitized enrollee date field", [
                    'field' => $dateField,
                    'original' => $originalDate,
                    'sanitized' => $enrolleeData[$dateField],
                    'format_used' => $dateFormat
                ]);
            }
        }

        $enrolleeData = $this->uppercaseStrings($enrolleeData);

        // Determine status and soft deletion based on employment_end_date
        $enrolleeData['status'] = 'ACTIVE';
        $shouldSoftDelete = false;

        // Check if employment_end_date exists (either sanitized or original)
        if ((isset($enrolleeData['employment_end_date']) && !empty($enrolleeData['employment_end_date'])) ||
            ($originalEmploymentEndDate && !empty(trim($originalEmploymentEndDate)))
        ) {
            if ($enrolleeData['employment_end_date'] <= date('Y-m-d')) {
                $enrolleeData['status'] = 'INACTIVE';
                $enrolleeData['enrollment_status'] = 'RESIGNED';
                $shouldSoftDelete = true;
            }
        }

        $principalQuery = Enrollee::with(['healthInsurance'])
            ->withTrashed()
            ->where('employee_id', $employeeId)
            ->where('enrollment_id', $enrollmentId);

        // Always include birth_date in the query to ensure proper uniqueness
        /* if (isset($enrolleeData['birth_date'])) {
            $principalQuery = $principalQuery->where('birth_date', $enrolleeData['birth_date']);
        } else {
            $principalQuery = $principalQuery->whereNull('birth_date');
        } */

        $existingPrincipal = $principalQuery->first();

        $isRenewal = $existingPrincipal && $existingPrincipal->healthInsurance
            ? $existingPrincipal->healthInsurance->is_renewal
            : false;

        if ($existingPrincipal) {

            return $existingPrincipal;
            /* if (method_exists($existingPrincipal, 'trashed') && $existingPrincipal->trashed()) {
                // Simplified logic: If record is trashed, restore it if no employment_end_date, otherwise update but keep trashed
                if (!$isRenewal)
                    $existingPrincipal->update($enrolleeData);
                //$existingPrincipal->restore();
            } else {
                // Check for changes before updating existing active record
                $hasChanges = false;
                $changes = [];

                foreach ($enrolleeData as $key => $value) {

                    // Skip timestamps and internal fields
                    if (in_array($key, ['created_at', 'updated_at', 'coverage_end_date'])) {
                        continue;
                    }

                    $currentValue = $existingPrincipal->getAttribute($key);
                    $normalizedCurrentValue = $this->normalizeValue($currentValue);
                    $normalizedNewValue = $this->normalizeValue($value);

                    if ($normalizedCurrentValue !== $normalizedNewValue) {
                        $hasChanges = true;
                        $changes[$key] = [
                            'old' => $currentValue,
                            'new' => $value,
                            'old_normalized' => $normalizedCurrentValue,
                            'new_normalized' => $normalizedNewValue
                        ];
                    }
                }

                // Update if there are changes
                if ($hasChanges) {
                    if (!$isRenewal)
                        $existingPrincipal->update($enrolleeData);
                }

                // Handle soft deletion if employment_end_date exists
                if ($shouldSoftDelete) {
                    $currentUserId = auth()->check() ? auth()->user()->id : 1;
                    $existingPrincipal->update(['deleted_by' => $currentUserId]);
                    $existingPrincipal->delete();
                }
            }
            return $existingPrincipal; */
        } else {

            // Ensure employee_id is set properly to prevent auto-generation
            if (!isset($enrolleeData['employee_id']) || empty($enrolleeData['employee_id'])) {
                $enrolleeData['employee_id'] = $employeeId;
            }

            try {
                $principal = Enrollee::create($enrolleeData);

                // Soft delete immediately if employment_end_date exists
                if ($shouldSoftDelete) {
                    $currentUserId = auth()->check() ? auth()->user()->id : 1;
                    $principal->update(['deleted_by' => $currentUserId]);
                    $principal->delete();
                }
            } catch (\Exception $e) {
                Log::error('Failed to create principal', [
                    'employee_id' => $employeeId,
                    'error' => $e->getMessage(),
                    'sql_error' => $e->getPrevious() ? $e->getPrevious()->getMessage() : 'N/A',
                    'enrollee_data' => $enrolleeData
                ]);
                throw $e;
            }

            return $principal;
        }
    }

    public function attachDependents(Request $request, string $dateFormat = 'auto'): JsonResponse
    {
        DB::beginTransaction();

        try {
            $enrolleeId = $request->input('enrollee_id');
            $dependentsData = $request->input('dependents', []);

            if (empty($enrolleeId)) {
                return response()->json(['message' => 'Enrollee ID is required'], 400);
            }

            if (empty($dependentsData)) {
                return response()->json(['message' => 'No dependents data provided'], 400);
            }

            // Verify enrollee exists (including soft deleted ones since we might need to attach to them)
            $enrollee = Enrollee::withTrashed()->find($enrolleeId);
            if (!$enrollee) {
                return response()->json(['message' => 'Enrollee not found with id: ' . $enrolleeId], 404);
            }

            $dependentFields = (new Dependent())->getFillable();
            $createdDependents = [];

            foreach ($dependentsData as $dependentData) {
                $filtered = array_intersect_key($dependentData, array_flip($dependentFields));

                // Sanitize all date fields in dependent data
                $dateFields = [
                    'birth_date',
                ];

                foreach ($dateFields as $dateField) {
                    if (isset($filtered[$dateField]) && !empty($filtered[$dateField])) {

                        $originalDate = $filtered[$dateField];

                        $filtered[$dateField] = $this->sanitizeDateWithFormat($filtered[$dateField], $dateFormat);
                        Log::info("Sanitized dependent date field", [
                            'field' => $dateField,
                            'original' => $originalDate,
                            'sanitized' => $filtered[$dateField],
                            'format_used' => $dateFormat
                        ]);
                    }
                }

                $filtered = $this->uppercaseStrings($filtered);
                $filtered['principal_id'] = $enrolleeId;

                // Check for existing dependent by principal_id and birth_date
                $dependentQuery = Dependent::withTrashed()->where('principal_id',  $enrolleeId);

                if (isset($filtered['birth_date']) && !empty($filtered['birth_date'])) {
                    $dependentQuery = $dependentQuery->where('birth_date', $filtered['birth_date']);
                }

                $existingDependent = $dependentQuery->first();

                if ($existingDependent) {

                    if ($existingDependent->trashed()) {
                        $existingDependent->restore();
                    }

                    $existingDependent->update($filtered);
                    $dependent = $existingDependent;
                } else {

                    $dependent = Dependent::create($filtered);
                }

                $createdDependents[] = $dependent;
            }

            DB::commit();
            return response()->json(['message' => 'Dependents attached successfully', 'dependents' => $createdDependents], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to attach dependents', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Process health insurance data and convert boolean fields
     */
    private function processHealthInsuranceData(array $healthInsuranceData, bool $defaultCompanyPaidValue = false): array
    {
        // Convert is_company_paid from 'Yes'/'No' to 1/0 if present
        if (isset($healthInsuranceData['is_company_paid'])) {
            if (is_numeric($healthInsuranceData['is_company_paid'])) {
                $healthInsuranceData['is_company_paid'] = (int)$healthInsuranceData['is_company_paid'];
            } else {
                $value = strtoupper(trim($healthInsuranceData['is_company_paid']));
                $healthInsuranceData['is_company_paid'] = ($value === 'YES') ? true : false;
            }
        } else {
            $healthInsuranceData['is_company_paid'] = true;
        }

        // Convert is_renewal from 'Yes'/'No' to 1/0 if present
        if (isset($healthInsuranceData['is_renewal'])) {
            if (is_numeric($healthInsuranceData['is_renewal'])) {
                $healthInsuranceData['is_renewal'] = (int)$healthInsuranceData['is_renewal'];
            } else {
                $value = strtoupper(trim($healthInsuranceData['is_renewal']));
                $healthInsuranceData['is_renewal'] = ($value === 'YES') ? true : false;
            }
        } else {
            $healthInsuranceData['is_renewal'] = false;
        }

        // Convert is_skipping from 'Yes'/'No' to 1/0 if present
        if (isset($healthInsuranceData['is_skipping'])) {
            if (is_numeric($healthInsuranceData['is_skipping'])) {
                $healthInsuranceData['is_skipping'] = (int)$healthInsuranceData['is_skipping'];
            } else {
                $value = strtoupper(trim($healthInsuranceData['is_skipping']));
                $healthInsuranceData['is_skipping'] = ($value === 'YES') ? true : false;
            }
        } else {
            $healthInsuranceData['is_skipping'] = false;

            if (isset($healthInsuranceData['reason_for_skipping'])) {
                if (!empty($healthInsuranceData['reason_for_skipping'])) {
                    $healthInsuranceData['is_skipping'] = true;
                }
            }
        }

        return $healthInsuranceData;
    }

    public function attachHealthInsurance(Request $request, string $dateFormat = 'auto'): JsonResponse
    {
        DB::beginTransaction();

        try {
            $enrolleeId = $request->input('enrollee_id');
            $dependentId = $request->input('dependent_id');
            $insuranceData = $request->input('insurance', []);

            if (!$enrolleeId && !$dependentId) {
                return response()->json(['message' => 'Either enrollee_id or dependent_id must be provided'], 400);
            }

            if (empty($insuranceData)) {
                return response()->json(['message' => 'No insurance data provided'], 400);
            }

            $insuranceFields = (new HealthInsurance())->getFillable();
            $filtered = array_intersect_key($insuranceData, array_flip($insuranceFields));

            // Sanitize all date fields in health insurance
            $dateFields = [
                'coverage_start_date',
                'coverage_end_date',
                'certificate_date_issued',
                'kyc_datestamp',
                'card_delivery_date',
            ];

            foreach ($dateFields as $dateField) {
                if (isset($filtered[$dateField]) && !empty($filtered[$dateField])) {
                    $originalDate = $filtered[$dateField];
                    $filtered[$dateField] = $this->sanitizeDateWithFormat($filtered[$dateField], $dateFormat);
                    Log::info("Sanitized insurance date field", [
                        'field' => $dateField,
                        'original' => $originalDate,
                        'sanitized' => $filtered[$dateField],
                        'format_used' => $dateFormat
                    ]);
                }
            }

            $filtered = $this->uppercaseStrings($filtered);

            if ($enrolleeId) {

                // Verify enrollee exists
                $enrollee = Enrollee::find($enrolleeId);

                if (!$enrollee) {
                    return response()->json(['message' => 'Enrollee not found with id: ' . $enrolleeId], 404);
                }

                $filtered['principal_id'] = $enrolleeId;
            } elseif ($dependentId) {

                // Verify dependent exists
                $dependent = Dependent::find($dependentId);

                if (!$dependent) {
                    return response()->json(['message' => 'Dependent not found with id: ' . $dependentId], 404);
                }

                $filtered['dependent_id'] = $dependentId;
            }

            // Check for existing insurance by principal_id or dependent_id
            $insuranceQuery = HealthInsurance::query();
            if (isset($filtered['principal_id'])) {

                $insuranceQuery->where('principal_id', $filtered['principal_id']);
            } elseif (isset($filtered['dependent_id'])) {

                $insuranceQuery->where('dependent_id', $filtered['dependent_id']);
            }

            $existingInsurance = $insuranceQuery->first();

            if ($existingInsurance) {
                $existingInsurance->update($filtered);
                $insurance = $existingInsurance;
            } else {
                $insurance = HealthInsurance::create($filtered);
            }
            DB::commit();
            return response()->json(['message' => 'Health insurance attached successfully', 'insurance' => $insurance], 200);
        } catch (\Exception $e) {

            DB::rollBack();
            return response()->json(['message' => 'Failed to attach health insurance', 'error' => $e->getMessage()], 500);
        }
    }
}
