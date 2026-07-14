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
use Modules\ClientMasterlist\App\Models\ImportLog;
use App\Models\Company;

use App\Http\Traits\DateSanitizer;
use App\Http\Traits\UppercaseInput;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportEnrolleeController extends Controller
{
    use UppercaseInput, DateSanitizer;

    /**
     * Store detailed import logging information
     */
    private $importLog = [
        'principals' => [],
        'dependents' => [],
        'summary' => [
            'total_principals' => 0,
            'total_dependents' => 0,
            'principals_created' => 0,
            'principals_updated' => 0,
            'dependents_created' => 0,
            'dependents_updated' => 0,
        ]
    ];

    /**
     * Add principal log entry with field mapping
     */
    private function logPrincipal(string $employeeId, string $action, array $enrolleeData, array $changes = [], array $healthInsuranceData = []): void
    {
        $this->importLog['principals'][] = [
            'employee_id' => $employeeId,
            'action' => $action,
            'enrollee_fields' => [
                'first_name' => $enrolleeData['first_name'] ?? null,
                'last_name' => $enrolleeData['last_name'] ?? null,
                'middle_name' => $enrolleeData['middle_name'] ?? null,
                'birth_date' => $enrolleeData['birth_date'] ?? null,
                'gender' => $enrolleeData['gender'] ?? null,
                'employment_start_date' => $enrolleeData['employment_start_date'] ?? null,
                'employment_end_date' => $enrolleeData['employment_end_date'] ?? null,
                'email1' => $enrolleeData['email1'] ?? null,
                'phone1' => $enrolleeData['phone1'] ?? null,
                'address' => $enrolleeData['address'] ?? null,
                'department' => $enrolleeData['department'] ?? null,
                'position' => $enrolleeData['position'] ?? null,
                'marital_status' => $enrolleeData['marital_status'] ?? null,
                'nationality' => $enrolleeData['nationality'] ?? null,
                'enrollment_status' => $enrolleeData['enrollment_status'] ?? null,
            ],
            'health_insurance_fields' => [
                'certificate_number' => $healthInsuranceData['certificate_number'] ?? null,
                'plan' => $healthInsuranceData['plan'] ?? null,
                'premium' => $healthInsuranceData['premium'] ?? null,
                'coverage_start_date' => $healthInsuranceData['coverage_start_date'] ?? null,
                'coverage_end_date' => $healthInsuranceData['coverage_end_date'] ?? null,
                'is_company_paid' => $healthInsuranceData['is_company_paid'] ?? null,
                'is_renewal' => $healthInsuranceData['is_renewal'] ?? null,
                'is_skipping' => $healthInsuranceData['is_skipping'] ?? null,
                'reason_for_skipping' => $healthInsuranceData['reason_for_skipping'] ?? null,
            ],
            'changes' => $changes,
            'timestamp' => now()->toDateTimeString(),
        ];

        if ($action === 'created') {
            $this->importLog['summary']['principals_created']++;
        } elseif ($action === 'updated' && !empty($changes)) {
            $this->importLog['summary']['principals_updated']++;
        }
    }

    /**
     * Add dependent log entry with field mapping
     */
    private function logDependent(int $principalId, string $employeeId, string $action, array $dependentData, array $changes = [], array $healthInsuranceData = []): void
    {
        $this->importLog['dependents'][] = [
            'principal_id' => $principalId,
            'principal_employee_id' => $employeeId,
            'action' => $action,
            'dependent_fields' => [
                'first_name' => $dependentData['first_name'] ?? null,
                'last_name' => $dependentData['last_name'] ?? null,
                'middle_name' => $dependentData['middle_name'] ?? null,
                'relation' => $dependentData['relation'] ?? null,
                'birth_date' => $dependentData['birth_date'] ?? null,
                'gender' => $dependentData['gender'] ?? null,
                'marital_status' => $dependentData['marital_status'] ?? null,
                'nationality' => $dependentData['nationality'] ?? null,
                'enrollment_status' => $dependentData['enrollment_status'] ?? null,
            ],
            'health_insurance_fields' => [
                'certificate_number' => $healthInsuranceData['certificate_number'] ?? null,
                'plan' => $healthInsuranceData['plan'] ?? null,
                'premium' => $healthInsuranceData['premium'] ?? null,
                'coverage_start_date' => $healthInsuranceData['coverage_start_date'] ?? null,
                'coverage_end_date' => $healthInsuranceData['coverage_end_date'] ?? null,
                'is_company_paid' => $healthInsuranceData['is_company_paid'] ?? null,
                'is_skipping' => $healthInsuranceData['is_skipping'] ?? null,
            ],
            'changes' => $changes,
            'timestamp' => now()->toDateTimeString(),
        ];

        if ($action === 'created') {
            $this->importLog['summary']['dependents_created']++;
        } elseif ($action === 'updated' && !empty($changes)) {
            $this->importLog['summary']['dependents_updated']++;
        }
    }

    /**
     * Save import log to database
     */
    private function saveImportLog(int $enrollmentId, string $dateFormat = 'auto', string $confidence = '0%', string $status = 'success', string $errorMessage = null): ImportLog
    {
        $importLog = ImportLog::create([
            'enrollment_id' => $enrollmentId,
            'import_date' => now(),
            'total_principals' => $this->importLog['summary']['total_principals'],
            'total_dependents' => $this->importLog['summary']['total_dependents'],
            'principals_created' => $this->importLog['summary']['principals_created'],
            'principals_updated' => $this->importLog['summary']['principals_updated'],
            'dependents_created' => $this->importLog['summary']['dependents_created'],
            'dependents_updated' => $this->importLog['summary']['dependents_updated'],
            'import_details' => $this->importLog,
            'date_format_detected' => $dateFormat,
            'date_format_confidence' => $confidence,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);

        Log::info('Import log saved to database', [
            'import_log_id' => $importLog->id,
            'enrollment_id' => $enrollmentId,
            'principals_created' => $this->importLog['summary']['principals_created'],
            'principals_updated' => $this->importLog['summary']['principals_updated'],
            'dependents_created' => $this->importLog['summary']['dependents_created'],
            'dependents_updated' => $this->importLog['summary']['dependents_updated'],
        ]);

        return $importLog;
    }

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

        // Handle boolean values consistently
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        // Convert to string and trim whitespace
        $normalized = trim((string) $value);

        // Handle empty string after trimming
        if ($normalized === '') {
            return null;
        }

        // Handle string representations of booleans
        $upperNormalized = strtoupper($normalized);
        if (in_array($upperNormalized, ['TRUE', 'FALSE', 'YES', 'NO'])) {
            return in_array($upperNormalized, ['TRUE', 'YES']) ? 1 : 0;
        }

        // Handle numeric values (including decimal precision issues)
        if (is_numeric($normalized)) {
            // If it's a whole number, convert to int, otherwise float with consistent precision
            if ((float) $normalized == (int) $normalized) {
                return (int) $normalized;
            } else {
                // Round to 2 decimal places to avoid precision issues
                return round((float) $normalized, 2);
            }
        }

        // Convert to uppercase for string comparison (since we use uppercaseStrings)
        if (is_string($value) && !is_numeric($normalized)) {
            $normalized = strtoupper($normalized);
        }

        return $normalized;
    }

    public function import(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $enrollees = $request->input('enrollees', []);
            $enrollmentId = $request->input('enrollment_id');

            // Initialize import log
            $this->importLog['summary']['total_principals'] = count($enrollees);

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

                // Handle employee_id with generated number format (employee_id-generated_number)
                $originalEmployeeId = $employeeId;
                if ($employeeId && strpos($employeeId, '-') !== false) {
                    $baseEmployeeId = explode('-', $employeeId)[0];

                    // Check if base employee_id exists as principal in this enrollment
                    $existingPrincipal = Enrollee::withTrashed()
                        ->where('employee_id', $baseEmployeeId)
                        ->where('enrollment_id', $enrollmentId)
                        ->first();

                    if ($existingPrincipal) {
                        // Use the base employee_id if principal exists
                        $employeeId = $baseEmployeeId;
                        $enrolleeData['employee_id'] = $baseEmployeeId;
                        Log::info("Found existing principal with base employee_id", [
                            'original_employee_id' => $originalEmployeeId,
                            'base_employee_id' => $baseEmployeeId,
                            'existing_principal_id' => $existingPrincipal->id
                        ]);
                    }
                    // If no existing principal found, keep the original employee_id with generated number
                }

                // Separate health insurance fields from enrollee data
                $healthInsuranceData = [];

                foreach ($insuranceFields as $field) {
                    if (array_key_exists($field, $enrolleeData)) {
                        $healthInsuranceData[$field] = $enrolleeData[$field];
                        unset($enrolleeData[$field]);
                    }
                }

                // Process health insurance data
                $isPrincipal = ($relation === 'PRINCIPAL' || $relation === 'EMPLOYEE' || $relation === 'EMPLOYEES');

                $healthInsuranceData = $this->processHealthInsuranceData($healthInsuranceData, true);
                $isRenewal = isset($this->processHealthInsuranceData($healthInsuranceData, true)['is_renewal']) ? $this->processHealthInsuranceData($healthInsuranceData, true)['is_renewal'] : false;

                if (!isset($enrolleeData['enrollment_status'])) {
                    // Set enrollment status based on health insurance data
                    if (!empty($healthInsuranceData['certificate_number']) && !$isRenewal) {
                        $enrolleeData['enrollment_status'] = 'APPROVED';
                    }

                    if (!empty($healthInsuranceData['certificate_number']) && $isRenewal) {
                        $enrolleeData['enrollment_status'] = 'FOR-RENEWAL';
                    }

                    if ($healthInsuranceData['is_skipping'] || !empty($healthInsuranceData['reason_for_skipping'])) {
                        $enrolleeData['enrollment_status'] = 'SKIPPED';
                    }
                }

                if ($isPrincipal) {

                    $principal = $this->createOrUpdatePrincipalWithSoftDelete($enrolleeData, $enrollmentId, $employeeId, $dateAnalysis['recommended_format']);

                    // Verify principal was created/updated successfully
                    if (!$principal || !$principal->id) {
                        throw new \Exception("Failed to create or update principal for employee {$employeeId}");
                    }

                    $principalMap[$employeeId] = $principal;

                    // Log principal import
                    $this->logPrincipal(
                        $employeeId,
                        $this->importLog['summary']['principals_created'] > 0 && in_array($employeeId, array_keys($principalMap)) ? 'updated' : 'created',
                        $enrolleeData,
                        [],
                        $healthInsuranceData
                    );

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

                    if ($relation === 'DEPENDENTS' || $relation === 'DEPENDENT') {
                        unset($enrolleeData['relation']);
                    }

                    if (!isset($dependentsByPrincipal[$employeeId])) {
                        $dependentsByPrincipal[$employeeId] = [];
                    }

                    $dependentsByPrincipal[$employeeId][] = [
                        'enrollee_data' => $enrolleeData,
                        'health_insurance_data' => $healthInsuranceData
                    ];
                }
            }

            // Update total dependents count
            $this->importLog['summary']['total_dependents'] = array_sum(array_map('count', $dependentsByPrincipal));

            // Process dependents
            foreach ($dependentsByPrincipal as $employeeId => $dependents) {
                $principal = $principalMap[$employeeId] ?? null;

                // If principal was not in this import batch, try to find them in the database
                if (!$principal) {
                    $principal = Enrollee::withTrashed()
                        ->where('employee_id', $employeeId)
                        ->where('enrollment_id', $enrollmentId)
                        ->first();

                    if ($principal) {
                        Log::info('Principal not in import batch but found in database', [
                            'employee_id' => $employeeId,
                            'principal_id' => $principal->id
                        ]);
                    } else {
                        Log::warning('Principal not found in import batch or database, skipping dependents', [
                            'employee_id' => $employeeId
                        ]);
                        continue;
                    }
                } else {
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
                            // Log dependent import
                            $this->logDependent(
                                $principal->id,
                                $employeeId,
                                'created',
                                $dependent['enrollee_data'],
                                [],
                                $healthInsuranceData
                            );

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

            // Save import log to database
            $importLog = $this->saveImportLog(
                $enrollmentId,
                $dateAnalysis['recommended_format'],
                $dateAnalysis['confidence'] ?? '0%',
                'success'
            );

            // Commit all transaction levels to ensure data is actually saved
            $transactionLevel = DB::transactionLevel();

            for ($i = 0; $i < $transactionLevel; $i++) {
                DB::commit();
            }

            return response()->json([
                'message' => 'Import successful',
                'import_log_id' => $importLog->id,
                'summary' => $this->importLog['summary'],
            ], 200);
        } catch (\Exception $e) {

            // Save import log with error status
            try {
                $this->saveImportLog(
                    $enrollmentId ?? 0,
                    'unknown',
                    '0%',
                    'failed',
                    $e->getMessage()
                );
            } catch (\Exception $logError) {
                Log::error('Failed to save error log', ['error' => $logError->getMessage()]);
            }

            // Rollback all transaction levels
            $transactionLevel = DB::transactionLevel();
            for ($i = 0; $i < $transactionLevel; $i++) {
                DB::rollBack();
            }

            return response()->json(['message' => 'Import failed', 'error' => $e->getMessage()], 500);
        }
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
                //$shouldSoftDelete = true;
            }

            //$healthInsuranceData['coverage_end_date'] = $enrolleeData['employment_end_date'];
        } else {
            if ((isset($healthInsuranceData['certificate_number']) && !empty($healthInsuranceData['certificate_number']))) {

                if (empty($healthInsuranceData['coverage_start_date'])) {
                    $healthInsuranceData['coverage_start_date'] = date('Y-m-d', strtotime('first day of next month'));
                }

                if (empty($healthInsuranceData['coverage_start_date'])) {
                    $healthInsuranceData['certificate_date_issued'] = $healthInsuranceData['coverage_start_date'];
                }

                $enrolleeData['status'] = 'ACTIVE';
                $enrolleeData['enrollment_status'] = 'APPROVED';
            }
        }

        $principalQuery = Enrollee::with(['healthInsurance'])
            ->withTrashed()
            ->where('employee_id', $employeeId)
            ->where('enrollment_id', $enrollmentId);

        $existingPrincipal = $principalQuery->first();

        $isRenewal = $existingPrincipal && $existingPrincipal->healthInsurance
            ? $existingPrincipal->healthInsurance->is_renewal
            : false;

        if ($existingPrincipal) {

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
                        'new_normalized' => $normalizedNewValue,
                        'old_type' => gettype($currentValue),
                        'new_type' => gettype($value)
                    ];
                }
            }

            // Debug: Log all field comparisons even when there are no changes
            if (!$hasChanges) {
                $allComparisons = [];
                foreach ($enrolleeData as $key => $value) {
                    if (in_array($key, ['created_at', 'updated_at', 'coverage_end_date'])) {
                        continue;
                    }
                    $currentValue = $existingPrincipal->getAttribute($key);
                    $normalizedCurrentValue = $this->normalizeValue($currentValue);
                    $normalizedNewValue = $this->normalizeValue($value);

                    $allComparisons[$key] = [
                        'old' => $currentValue,
                        'new' => $value,
                        'old_normalized' => $normalizedCurrentValue,
                        'new_normalized' => $normalizedNewValue,
                        'are_equal' => $normalizedCurrentValue === $normalizedNewValue,
                        'old_type' => gettype($currentValue),
                        'new_type' => gettype($value)
                    ];
                }

                Log::info('Principal comparison details (no changes detected)', [
                    'employee_id' => $employeeId,
                    'comparisons' => $allComparisons
                ]);
            }

            // Update if there are changes
            if ($hasChanges) {
                Log::info('Updating existing principal with changes', [
                    'employee_id' => $employeeId,
                    'changes' => $changes,
                    'is_renewal' => $isRenewal
                ]);

                $existingPrincipal->update($enrolleeData);
            } else {
                Log::info('No principal changes detected, skipping update', [
                    'employee_id' => $employeeId,
                    'is_renewal' => $isRenewal
                ]);
            }

            // Handle soft deletion if employment_end_date exists
            if ($shouldSoftDelete) {
                Log::info('Soft deleting principal due to employment_end_date', [
                    'employee_id' => $employeeId,
                    'employment_end_date' => $enrolleeData['employment_end_date'] ?? null
                ]);
                $currentUserId = auth()->check() ? auth()->user()->id : 1;
                $existingPrincipal->update(['deleted_by' => $currentUserId]);
                $existingPrincipal->delete();
            } else {
                Log::info('No soft deletion needed for principal', [
                    'employee_id' => $employeeId,
                    'has_employment_end_date' => isset($enrolleeData['employment_end_date']) && !empty($enrolleeData['employment_end_date'])
                ]);
            }

            return $existingPrincipal;
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
            $hasChanges = false;

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
                    // Check for changes before updating
                    $dependentHasChanges = false;
                    $dependentChanges = [];

                    foreach ($filtered as $key => $value) {
                        if (in_array($key, ['created_at', 'updated_at'])) {
                            continue;
                        }
                        $currentValue = $existingDependent->getAttribute($key);
                        $normalizedCurrentValue = $this->normalizeValue($currentValue);
                        $normalizedNewValue = $this->normalizeValue($value);

                        if ($normalizedCurrentValue !== $normalizedNewValue) {
                            $dependentHasChanges = true;
                            $dependentChanges[$key] = [
                                'old' => $currentValue,
                                'new' => $value,
                                'old_normalized' => $normalizedCurrentValue,
                                'new_normalized' => $normalizedNewValue
                            ];
                        }
                    }

                    if ($dependentHasChanges) {
                        Log::info("Updating existing dependent with changes", [
                            'dependent_id' => $existingDependent->id,
                            'principal_id' => $enrolleeId,
                            'changes' => $dependentChanges
                        ]);
                        $existingDependent->update($filtered);
                        $hasChanges = true;
                    } else {
                        Log::info("No changes detected for existing dependent, skipping update", [
                            'dependent_id' => $existingDependent->id,
                            'principal_id' => $enrolleeId,
                            'birth_date' => $filtered['birth_date'] ?? null
                        ]);
                    }
                    $dependent = $existingDependent;
                } else {
                    Log::info("Creating new dependent", [
                        'principal_id' => $enrolleeId,
                        'birth_date' => $filtered['birth_date'] ?? null
                    ]);
                    $dependent = Dependent::create($filtered);
                    $hasChanges = true;
                }

                $createdDependents[] = $dependent;
            }

            // Always update the principal's updated_at timestamp when importing dependents
            $enrollee->touch();
            Log::info("Updated principal timestamp due to dependent import", [
                'principal_id' => $enrolleeId,
                'dependents_processed' => count($createdDependents),
                'had_changes' => $hasChanges
            ]);

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
    private function processHealthInsuranceData(array $healthInsuranceData, bool $withRenewalChecking = false): array
    {
        // Convert is_company_paid from 'Yes'/'No' to 1/0 if present
        if (isset($healthInsuranceData['is_company_paid'])) {
            if (is_numeric($healthInsuranceData['is_company_paid'])) {
                $healthInsuranceData['is_company_paid'] = (int)$healthInsuranceData['is_company_paid'];
            } else {
                $value = strtoupper(trim($healthInsuranceData['is_company_paid']));
                $healthInsuranceData['is_company_paid'] = ($value === 'YES') ? 1 : 0;
            }
        } else {
            $healthInsuranceData['is_company_paid'] = 0;
        }

        if ($withRenewalChecking) {
            // Convert is_renewal from 'Yes'/'No' to 1/0 if present
            if (isset($healthInsuranceData['is_renewal'])) {
                if (is_numeric($healthInsuranceData['is_renewal'])) {
                    $healthInsuranceData['is_renewal'] = (int)$healthInsuranceData['is_renewal'];
                } else {
                    $value = strtoupper(trim($healthInsuranceData['is_renewal']));
                    $healthInsuranceData['is_renewal'] = ($value === 'YES') ? 1 : 0;
                }
            }
        }

        // Convert is_skipping from 'Yes'/'No' to 1/0 if present
        if (isset($healthInsuranceData['is_skipping'])) {
            if (is_numeric($healthInsuranceData['is_skipping'])) {
                $healthInsuranceData['is_skipping'] = (int)$healthInsuranceData['is_skipping'];
            } else {
                $value = strtoupper(trim($healthInsuranceData['is_skipping']));
                $healthInsuranceData['is_skipping'] = ($value === 'YES') ? 1 : 0;
            }
        } else {
            $healthInsuranceData['is_skipping'] = 0;
            if (isset($healthInsuranceData['reason_for_skipping'])) {
                if (!empty($healthInsuranceData['reason_for_skipping'])) {
                    $healthInsuranceData['is_skipping'] = 1;
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

                // If certificate_number is present, set enrollment_status to APPROVED
                // and populate coverage_start_date and certificate_date_issued if empty
                if (!empty($filtered['certificate_number'])) {
                    if (empty($filtered['coverage_start_date'])) {
                        $filtered['coverage_start_date'] = date('Y-m-d', strtotime('first day of next month'));
                    }
                    if (empty($filtered['certificate_date_issued'])) {
                        $filtered['certificate_date_issued'] = $filtered['coverage_start_date'];
                    }
                    $dependent->update(['enrollment_status' => 'APPROVED']);
                    Log::info("Set dependent enrollment_status to APPROVED due to certificate_number", [
                        'dependent_id' => $dependentId,
                        'certificate_number' => $filtered['certificate_number'],
                        'coverage_start_date' => $filtered['coverage_start_date'],
                        'certificate_date_issued' => $filtered['certificate_date_issued']
                    ]);
                }
            }

            // Check for existing insurance by principal_id or dependent_id
            $insuranceQuery = HealthInsurance::query();
            if (isset($filtered['principal_id'])) {
                $insuranceQuery->where('principal_id', $filtered['principal_id']);
            } elseif (isset($filtered['dependent_id'])) {
                $insuranceQuery->where('dependent_id', $filtered['dependent_id']);
            }

            $existingInsurance = $insuranceQuery->first();

            $hasInsuranceChanges = false;

            if ($existingInsurance) {
                // Check for changes before updating
                $insuranceChanges = [];

                foreach ($filtered as $key => $value) {
                    if (in_array($key, ['created_at', 'updated_at'])) {
                        continue;
                    }
                    $currentValue = $existingInsurance->getAttribute($key);
                    $normalizedCurrentValue = $this->normalizeValue($currentValue);
                    $normalizedNewValue = $this->normalizeValue($value);

                    if ($normalizedCurrentValue !== $normalizedNewValue) {
                        $hasInsuranceChanges = true;
                        $insuranceChanges[$key] = [
                            'old' => $currentValue,
                            'new' => $value,
                            'old_normalized' => $normalizedCurrentValue,
                            'new_normalized' => $normalizedNewValue
                        ];
                    }
                }

                if ($hasInsuranceChanges) {
                    Log::info("Updating existing health insurance with changes", [
                        'insurance_id' => $existingInsurance->id,
                        'principal_id' => $enrolleeId ?? null,
                        'dependent_id' => $dependentId ?? null,
                        'changes' => $insuranceChanges
                    ]);
                    $existingInsurance->update($filtered);
                } else {
                    Log::info("No changes detected for existing health insurance, skipping update", [
                        'insurance_id' => $existingInsurance->id,
                        'principal_id' => $enrolleeId ?? null,
                        'dependent_id' => $dependentId ?? null
                    ]);
                }
                $insurance = $existingInsurance;
            } else {
                Log::info("Creating new health insurance", [
                    'principal_id' => $enrolleeId ?? null,
                    'dependent_id' => $dependentId ?? null
                ]);
                $insurance = HealthInsurance::create($filtered);
                $hasInsuranceChanges = true;
            }

            // Only update the principal's updated_at timestamp when there are actual changes
            if ($hasInsuranceChanges) {
                if ($enrolleeId) {
                    // Direct principal insurance update
                    $enrollee->touch();
                    Log::info("Updated principal timestamp due to health insurance changes", [
                        'principal_id' => $enrolleeId,
                        'insurance_type' => 'principal',
                        'actual_changes' => true
                    ]);
                } elseif ($dependentId) {
                    // Dependent insurance update - update the principal through the dependent
                    if ($dependent && $dependent->principal_id) {
                        $principal = Enrollee::find($dependent->principal_id);
                        if ($principal) {
                            $principal->touch();
                            Log::info("Updated principal timestamp due to dependent health insurance changes", [
                                'principal_id' => $dependent->principal_id,
                                'dependent_id' => $dependentId,
                                'insurance_type' => 'dependent',
                                'actual_changes' => true
                            ]);
                        }
                    }
                }
            } else {
                Log::info("No health insurance changes detected, principal timestamp not updated", [
                    'principal_id' => $enrolleeId ?? ($dependent->principal_id ?? null),
                    'dependent_id' => $dependentId ?? null,
                    'insurance_type' => $enrolleeId ? 'principal' : 'dependent'
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Health insurance attached successfully', 'insurance' => $insurance], 200);
        } catch (\Exception $e) {

            DB::rollBack();
            return response()->json(['message' => 'Failed to attach health insurance', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Download import log as text file for super admin
     */
    public function downloadImportLog(int $importLogId)
    {
        try {
            $importLog = ImportLog::with('enrollment')->find($importLogId);

            if (!$importLog) {
                return response()->json(['message' => 'Import log not found'], 404);
            }

            // Generate text report
            $report = $this->generateImportReport($importLog);

            // Generate filename with enrollment and date info
            $enrollment = $importLog->enrollment;
            $filename = "import_log_enrollment-{$enrollment->id}_{$importLog->import_date->format('Y-m-d_His')}.txt";

            return response($report, 200)
                ->header('Content-Type', 'text/plain; charset=utf-8')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        } catch (\Exception $e) {
            Log::error('Failed to download import log', [
                'import_log_id' => $importLogId,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Failed to download import log', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get import logs for an enrollment
     */
    public function getImportLogs(Request $request)
    {
        try {
            $enrollmentId = $request->input('enrollment_id');

            if (!$enrollmentId) {
                return response()->json(['message' => 'Enrollment ID is required'], 400);
            }

            $logs = ImportLog::where('enrollment_id', $enrollmentId)
                ->orderBy('import_date', 'desc')
                ->get();

            return response()->json([
                'data' => $logs,
                'count' => $logs->count(),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to get import logs', [
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Failed to get import logs', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate formatted text report from import log
     */
    private function generateImportReport(ImportLog $importLog): string
    {
        $details = $importLog->import_details ?? [];
        $summary = $details['summary'] ?? [];
        $principals = $details['principals'] ?? [];
        $dependents = $details['dependents'] ?? [];

        $report = "=" . str_repeat("=", 98) . "\n";
        $report .= "IMPORT LOG REPORT\n";
        $report .= "=" . str_repeat("=", 98) . "\n\n";

        // Header Information
        $report .= "IMPORT INFORMATION:\n";
        $report .= "-" . str_repeat("-", 98) . "\n";
        $report .= sprintf("Import Log ID:          %s\n", $importLog->id);
        $report .= sprintf("Enrollment ID:          %s\n", $importLog->enrollment_id);
        $report .= sprintf("Import Date:            %s\n", $importLog->import_date->format('Y-m-d H:i:s'));
        $report .= sprintf("Status:                 %s\n", strtoupper($importLog->status));
        $report .= sprintf("Date Format Detected:   %s\n", $importLog->date_format_detected);
        $report .= sprintf("Date Confidence:        %s\n", $importLog->date_format_confidence);

        if ($importLog->error_message) {
            $report .= sprintf("Error Message:          %s\n", $importLog->error_message);
        }

        $report .= "\n";

        // Summary Statistics
        $report .= "IMPORT SUMMARY:\n";
        $report .= "-" . str_repeat("-", 98) . "\n";
        $report .= sprintf("Total Principals:       %d\n", $summary['total_principals'] ?? 0);
        $report .= sprintf("Principals Created:     %d\n", $summary['principals_created'] ?? 0);
        $report .= sprintf("Principals Updated:     %d\n", $summary['principals_updated'] ?? 0);
        $report .= sprintf("Total Dependents:       %d\n", $summary['total_dependents'] ?? 0);
        $report .= sprintf("Dependents Created:     %d\n", $summary['dependents_created'] ?? 0);
        $report .= sprintf("Dependents Updated:     %d\n", $summary['dependents_updated'] ?? 0);

        $report .= "\n";

        // Principals Details
        if (!empty($principals)) {
            $report .= "PRINCIPALS IMPORTED:\n";
            $report .= "=" . str_repeat("=", 98) . "\n\n";

            foreach ($principals as $index => $principal) {
                $report .= sprintf("PRINCIPAL #%d\n", $index + 1);
                $report .= "-" . str_repeat("-", 98) . "\n";
                $report .= sprintf("Employee ID:            %s\n", $principal['employee_id']);
                $report .= sprintf("Action:                 %s\n", strtoupper($principal['action']));
                $report .= sprintf("Timestamp:              %s\n", $principal['timestamp']);

                $report .= "\n  ENROLLEE FIELDS:\n";
                if (isset($principal['enrollee_fields']) && is_array($principal['enrollee_fields'])) {
                    foreach ($principal['enrollee_fields'] as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $report .= sprintf("    %-30s : %s\n", ucwords(str_replace('_', ' ', $key)), $value);
                        }
                    }
                }

                $report .= "\n  HEALTH INSURANCE FIELDS:\n";
                if (isset($principal['health_insurance_fields']) && is_array($principal['health_insurance_fields'])) {
                    $hasInsuranceData = false;
                    foreach ($principal['health_insurance_fields'] as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $report .= sprintf("    %-30s : %s\n", ucwords(str_replace('_', ' ', $key)), $value);
                            $hasInsuranceData = true;
                        }
                    }
                    if (!$hasInsuranceData) {
                        $report .= "    (No health insurance data)\n";
                    }
                }

                if (!empty($principal['changes'])) {
                    $report .= "\n  CHANGES MADE:\n";
                    foreach ($principal['changes'] as $field => $changeData) {
                        $report .= sprintf("    - %s: '%s' → '%s'\n", 
                            ucwords(str_replace('_', ' ', $field)), 
                            $changeData['old'] ?? 'NULL', 
                            $changeData['new'] ?? 'NULL'
                        );
                    }
                }

                $report .= "\n";
            }
        }

        // Dependents Details
        if (!empty($dependents)) {
            $report .= "\nDEPENDENTS IMPORTED:\n";
            $report .= "=" . str_repeat("=", 98) . "\n\n";

            foreach ($dependents as $index => $dependent) {
                $report .= sprintf("DEPENDENT #%d\n", $index + 1);
                $report .= "-" . str_repeat("-", 98) . "\n";
                $report .= sprintf("Principal Employee ID:  %s\n", $dependent['principal_employee_id']);
                $report .= sprintf("Principal ID:           %s\n", $dependent['principal_id']);
                $report .= sprintf("Action:                 %s\n", strtoupper($dependent['action']));
                $report .= sprintf("Timestamp:              %s\n", $dependent['timestamp']);

                $report .= "\n  DEPENDENT FIELDS:\n";
                if (isset($dependent['dependent_fields']) && is_array($dependent['dependent_fields'])) {
                    foreach ($dependent['dependent_fields'] as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $report .= sprintf("    %-30s : %s\n", ucwords(str_replace('_', ' ', $key)), $value);
                        }
                    }
                }

                $report .= "\n  HEALTH INSURANCE FIELDS:\n";
                if (isset($dependent['health_insurance_fields']) && is_array($dependent['health_insurance_fields'])) {
                    $hasInsuranceData = false;
                    foreach ($dependent['health_insurance_fields'] as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $report .= sprintf("    %-30s : %s\n", ucwords(str_replace('_', ' ', $key)), $value);
                            $hasInsuranceData = true;
                        }
                    }
                    if (!$hasInsuranceData) {
                        $report .= "    (No health insurance data)\n";
                    }
                }

                if (!empty($dependent['changes'])) {
                    $report .= "\n  CHANGES MADE:\n";
                    foreach ($dependent['changes'] as $field => $changeData) {
                        $report .= sprintf("    - %s: '%s' → '%s'\n", 
                            ucwords(str_replace('_', ' ', $field)), 
                            $changeData['old'] ?? 'NULL', 
                            $changeData['new'] ?? 'NULL'
                        );
                    }
                }

                $report .= "\n";
            }
        }

        $report .= "\n" . "=" . str_repeat("=", 98) . "\n";
        $report .= "END OF IMPORT LOG REPORT\n";
        $report .= "=" . str_repeat("=", 98) . "\n";

        return $report;
    }
}

