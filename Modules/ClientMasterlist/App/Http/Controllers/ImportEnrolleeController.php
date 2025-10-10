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
            $principalMap = [];

            // Pass 1: Create principals
            $insuranceFields = (new HealthInsurance())->getFillable();

            foreach ($enrollees as $enrolleeData) {

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

                // If certificate_number is present, set status to approved
                if (!empty($healthInsuranceData['certificate_number'])) {
                    $enrolleeData['enrollment_status'] = 'APPROVED';
                }

                // Convert is_company_paid from 'Yes'/'No' to 1/0 if present
                if (isset($healthInsuranceData['is_company_paid'])) {
                    // Check if already 1 or 0 (numeric), otherwise convert from 'YES'/'NO'
                    if (is_numeric($healthInsuranceData['is_company_paid'])) {
                        $healthInsuranceData['is_company_paid'] = (int)$healthInsuranceData['is_company_paid'];
                    } else {
                        $value = strtoupper(trim($healthInsuranceData['is_company_paid']));
                        $healthInsuranceData['is_company_paid'] = ($value === 'YES') ? 1 : 0;
                    }
                } else {
                    $healthInsuranceData['is_company_paid'] = 0; // Default to 0 if not specified
                }

                // Convert is_renewal from 'Yes'/'No' to 1/0 if present
                if (isset($healthInsuranceData['is_renewal'])) {
                    // Check if already 1 or 0 (numeric), otherwise convert from 'YES'/'NO'
                    if (is_numeric($healthInsuranceData['is_renewal'])) {
                        $healthInsuranceData['is_renewal'] = (int)$healthInsuranceData['is_renewal'];
                    } else {
                        $value = strtoupper(trim($healthInsuranceData['is_renewal']));
                        $healthInsuranceData['is_renewal'] = ($value === 'YES') ? 1 : 0;
                    }

                    if ($healthInsuranceData['is_renewal']) {
                        $enrolleeData['enrollment_status'] = 'FOR-RENEWAL';
                    }
                } else {
                    $healthInsuranceData['is_renewal'] = 0; // Default to 0 if not specified
                }

                if ($relation === 'PRINCIPAL' || $relation === 'EMPLOYEE') {

                    // Check for existing principal by employee_id and birth_date
                    $enrolleeData['enrollment_id'] = $enrollmentId;

                    // Sanitize all date fields in enrollee data
                    $enrolleeDateFields = [
                        'birth_date',
                        'employment_start_date',
                        'employment_end_date',
                    ];

                    foreach ($enrolleeDateFields as $dateField) {
                        if (isset($enrolleeData[$dateField]) && !empty($enrolleeData[$dateField])) {

                            $originalDate = $enrolleeData[$dateField];
                            $enrolleeData[$dateField] = $this->sanitizeDate($enrolleeData[$dateField]);

                            Log::info("Sanitized enrollee date field", [
                                'field' => $dateField,
                                'original' => $originalDate,
                                'sanitized' => $enrolleeData[$dateField]
                            ]);
                        }
                    }

                    $enrolleeData = $this->uppercaseStrings($enrolleeData);

                    $principalQuery = Enrollee::withTrashed()->where('employee_id', $employeeId);

                    if ($enrolleeData['birth_date'] !== null) {
                        $principalQuery = $principalQuery->where('birth_date', $enrolleeData['birth_date']);
                    }

                    $existingPrincipal = $principalQuery->first();

                    if ($existingPrincipal) {
                        if (method_exists($existingPrincipal, 'trashed') && $existingPrincipal->trashed()) {
                            $existingPrincipal->restore();
                            // After restore, update with new data
                            $existingPrincipal->update($enrolleeData);
                        } else {
                            $existingPrincipal->update($enrolleeData);
                        }
                        $principal = $existingPrincipal;
                    } else {
                        $principal = Enrollee::create($enrolleeData);
                    }

                    $principalMap[$employeeId] = $principal;

                    // Health insurance for principal
                    if (!empty($healthInsuranceData)) {
                        $healthInsuranceData['principal_id'] = $principal->id;
                        $filtered = array_intersect_key($healthInsuranceData, array_flip($insuranceFields));

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
                                $filtered[$dateField] = $this->sanitizeDate($filtered[$dateField]);
                                Log::info("Sanitized insurance date field", [
                                    'field' => $dateField,
                                    'original' => $originalDate,
                                    'sanitized' => $filtered[$dateField]
                                ]);
                            }
                        }

                        $filtered = $this->uppercaseStrings($filtered);

                        // Compare all provided insurance fields for a match
                        $insuranceQuery = HealthInsurance::query();
                        $insuranceQuery->where('principal_id', $principal->id);

                        $existingInsurance = $insuranceQuery->first();
                        if ($existingInsurance) {
                            $existingInsurance->update($filtered);
                        } else {
                            HealthInsurance::create($filtered);
                        }
                    }
                }
            }

            // Pass 2: Create dependents and link to principal by employee_id
            foreach ($enrollees as $enrolleeData) {

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

                // If certificate_number is present, set status to approved
                if (!empty($healthInsuranceData['certificate_number'])) {
                    $enrolleeData['enrollment_status'] = 'APPROVED';
                }

                // Convert is_company_paid from 'Yes'/'No' to 1/0 if present
                if (isset($healthInsuranceData['is_company_paid'])) {
                    // Check if already 1 or 0 (numeric), otherwise convert from 'YES'/'NO'
                    if (is_numeric($healthInsuranceData['is_company_paid'])) {
                        $healthInsuranceData['is_company_paid'] = (int)$healthInsuranceData['is_company_paid'];
                    } else {
                        $value = strtoupper(trim($healthInsuranceData['is_company_paid']));
                        $healthInsuranceData['is_company_paid'] = ($value === 'YES') ? 1 : 0;
                    }
                } else {
                    $healthInsuranceData['is_company_paid'] = 1; // Default to 1 if not specified
                }

                // Convert is_renewal from 'Yes'/'No' to 1/0 if present
                if (isset($healthInsuranceData['is_renewal'])) {
                    // Check if already 1 or 0 (numeric), otherwise convert from 'YES'/'NO'
                    if (is_numeric($healthInsuranceData['is_renewal'])) {
                        $healthInsuranceData['is_renewal'] = (int)$healthInsuranceData['is_renewal'];
                    } else {
                        $value = strtoupper(trim($healthInsuranceData['is_renewal']));
                        $healthInsuranceData['is_renewal'] = ($value === 'YES') ? 1 : 0;
                    }

                    if ($healthInsuranceData['is_renewal']) {
                        $enrolleeData['enrollment_status'] = 'FOR-RENEWAL';
                    }
                } else {
                    $healthInsuranceData['is_renewal'] = 0; // Default to 0 if not specified
                }

                if (isset($healthInsuranceData['is_skipping'])) {
                    // Check if already 1 or 0 (numeric), otherwise convert from 'YES'/'NO'
                    if (is_numeric($healthInsuranceData['is_skipping'])) {
                        $healthInsuranceData['is_skipping'] = (int)$healthInsuranceData['is_skipping'];
                    } else {
                        $value = strtoupper(trim($healthInsuranceData['is_skipping']));
                        $healthInsuranceData['is_skipping'] = ($value === 'YES') ? 1 : 0;
                    }

                    if ($healthInsuranceData['is_skipping']) {
                        $enrolleeData['enrollment_status'] = 'SKIPPED';
                    }
                } else {
                    $healthInsuranceData['is_skipping'] = 0; // Default to 0 if not specified
                }

                if ($relation !== 'PRINCIPAL' && $relation !== 'EMPLOYEE') {
                    $principal = $principalMap[$employeeId] ?? null;
                    if ($principal) {

                        $enrolleeData['principal_id'] = $principal->id;

                        // Set principal_employee_id using principal's employee_id
                        $enrolleeData['principal_employee_id'] = $principal->employee_id;

                        // Sanitize all date fields in enrollee data
                        $enrolleeDateFields = [
                            'birth_date',
                            'employment_start_date',
                            'employment_end_date',
                        ];

                        foreach ($enrolleeDateFields as $dateField) {
                            if (isset($enrolleeData[$dateField]) && !empty($enrolleeData[$dateField])) {

                                $originalDate = $enrolleeData[$dateField];
                                $enrolleeData[$dateField] = $this->sanitizeDate($enrolleeData[$dateField]);

                                Log::info("Sanitized enrollee date field", [
                                    'field' => $dateField,
                                    'original' => $originalDate,
                                    'sanitized' => $enrolleeData[$dateField]
                                ]);
                            }
                        }

                        $enrolleeData = $this->uppercaseStrings($enrolleeData);

                        // Check for existing dependent by employee_id and birth_date
                        $dependentQuery = Dependent::withTrashed()->where('principal_id',  $principal->id);

                        if ($enrolleeData['birth_date'] !== null) {
                            $dependentQuery = $dependentQuery->where('birth_date', $enrolleeData['birth_date']);
                        }

                        $existingDependent = $dependentQuery->first();
                        if ($existingDependent) {
                            if ($existingDependent->trashed()) {
                                $existingDependent->restore();
                            }
                            $existingDependent->update($enrolleeData);
                            $dependent = $existingDependent;
                        } else {
                            $dependent = Dependent::create($enrolleeData);
                        }

                        // Health insurance for dependent
                        if (!empty($healthInsuranceData)) {
                            $healthInsuranceData['dependent_id'] = $dependent->id;
                            $filtered = array_intersect_key($healthInsuranceData, array_flip($insuranceFields));

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
                                    $filtered[$dateField] = $this->sanitizeDate($filtered[$dateField]);
                                    Log::info("Sanitized insurance date field", [
                                        'field' => $dateField,
                                        'original' => $originalDate,
                                        'sanitized' => $filtered[$dateField]
                                    ]);
                                }
                            }
                            $filtered = $this->uppercaseStrings($filtered);
                            // Compare all provided insurance fields for a match
                            $insuranceQuery = HealthInsurance::query();
                            $insuranceQuery->where('dependent_id', $dependent->id);

                            $existingInsurance = $insuranceQuery->first();
                            if ($existingInsurance) {
                                $existingInsurance->update($filtered);
                            } else {
                                HealthInsurance::create($filtered);
                            }
                        }
                    }
                }
            }

            DB::commit();
            return response()->json(['message' => 'Import successful'], 200);
        } catch (\Exception $e) {

            DB::rollBack();
            return response()->json(['message' => 'Import failed', 'error' => $e->getMessage()], 500);
        }
    }

    public function importWithCompanyAndProvider(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $enrollees = $request->input('enrollees', []);

            // Debug logging
            Log::info('Import request data:', [
                'enrollees_count' => count($enrollees),
                'first_enrollee' => $enrollees[0] ?? null
            ]);

            $principalMap = [];

            $insuranceFields = (new HealthInsurance())->getFillable();

            foreach ($enrollees as $enrolleeData) {
                $employeeId = $enrolleeData['employee_id'] ?? null;

                // Find enrollment by company_code and insurance_provider_title from each enrollee
                $enrolleeCompanyCode = $enrolleeData['company_code'] ?? null;
                $enrolleeInsuranceProviderTitle = $enrolleeData['insurance_provider_title'] ?? null;

                if ($enrolleeCompanyCode && $enrolleeInsuranceProviderTitle) {
                    // Find company by company_code
                    $company = Company::where('company_code', strtoupper($enrolleeCompanyCode))->first();
                    if (!$company) {
                        return response()->json(['message' => 'Company not found with code: ' . $enrolleeCompanyCode], 404);
                    }

                    // Find insurance provider by title
                    $insuranceProvider = InsuranceProvider::where('title', strtoupper($enrolleeInsuranceProviderTitle))->first();
                    if (!$insuranceProvider) {
                        return response()->json(['message' => 'Insurance provider not found with title: ' . $enrolleeInsuranceProviderTitle], 404);
                    }

                    // Find enrollment by company_id and insurance_provider_id
                    $enrollment = Enrollment::where('company_id', $company->id)
                        ->where('insurance_provider_id', $insuranceProvider->id)
                        ->first();

                    if ($enrollment) {
                        $currentEnrollmentId = $enrollment->id;
                    } else {
                        return response()->json(['message' => 'No enrollment found for company: ' . $enrolleeCompanyCode . ' and insurance provider: ' . $enrolleeInsuranceProviderTitle], 404);
                    }
                } else {
                    // Fallback to request-level enrollment_id if not provided per enrollee
                    Log::warning('Missing company/provider info for enrollee', [
                        'employee_id' => $employeeId,
                        'company_code' => $enrolleeCompanyCode,
                        'insurance_provider_title' => $enrolleeInsuranceProviderTitle
                    ]);
                    return response()->json(['message' => 'Missing company_code or insurance_provider_title for employee: ' . $employeeId], 400);
                }

                // Remove company_code and insurance_provider_title from enrollee data as they're not enrollee fields
                unset($enrolleeData['company_code']);
                unset($enrolleeData['insurance_provider_title']);

                // Separate health insurance fields from enrollee data
                $healthInsuranceData = [];

                foreach ($insuranceFields as $field) {
                    if (array_key_exists($field, $enrolleeData)) {
                        $healthInsuranceData[$field] = $enrolleeData[$field];
                        unset($enrolleeData[$field]);
                    }
                }

                // If certificate_number is present, set status to approved
                if (!empty($healthInsuranceData['certificate_number'])) {
                    $enrolleeData['enrollment_status'] = 'APPROVED';
                }

                // Convert is_company_paid from 'Yes'/'No' to 1/0 if present
                if (isset($healthInsuranceData['is_company_paid'])) {
                    // Check if already 1 or 0 (numeric), otherwise convert from 'YES'/'NO'
                    if (is_numeric($healthInsuranceData['is_company_paid'])) {
                        $healthInsuranceData['is_company_paid'] = (int)$healthInsuranceData['is_company_paid'];
                    } else {
                        $value = strtoupper(trim($healthInsuranceData['is_company_paid']));
                        $healthInsuranceData['is_company_paid'] = ($value === 'YES') ? 1 : 0;
                    }
                } else {
                    $healthInsuranceData['is_company_paid'] = 0; // Default to 0 if not specified
                }

                // Convert is_renewal from 'Yes'/'No' to 1/0 if present
                if (isset($healthInsuranceData['is_renewal'])) {
                    // Check if already 1 or 0 (numeric), otherwise convert from 'YES'/'NO'
                    if (is_numeric($healthInsuranceData['is_renewal'])) {
                        $healthInsuranceData['is_renewal'] = (int)$healthInsuranceData['is_renewal'];
                    } else {
                        $value = strtoupper(trim($healthInsuranceData['is_renewal']));
                        $healthInsuranceData['is_renewal'] = ($value === 'YES') ? 1 : 0;
                    }

                    if ($healthInsuranceData['is_renewal']) {
                        $enrolleeData['enrollment_status'] = 'FOR-RENEWAL';
                    }
                } else {
                    $healthInsuranceData['is_renewal'] = 0; // Default to 0 if not specified
                }

                // Check for existing principal by employee_id and birth_date
                $enrolleeData['enrollment_id'] = $currentEnrollmentId;

                // Sanitize all date fields in enrollee data
                $enrolleeDateFields = [
                    'birth_date',
                    'employment_start_date',
                    'employment_end_date',
                ];

                foreach ($enrolleeDateFields as $dateField) {
                    if (isset($enrolleeData[$dateField]) && !empty($enrolleeData[$dateField])) {

                        $originalDate = $enrolleeData[$dateField];
                        $enrolleeData[$dateField] = $this->sanitizeDate($enrolleeData[$dateField]);

                        Log::info("Sanitized enrollee date field", [
                            'field' => $dateField,
                            'original' => $originalDate,
                            'sanitized' => $enrolleeData[$dateField]
                        ]);
                    }
                }

                $enrolleeData = $this->uppercaseStrings($enrolleeData);

                $principalQuery = Enrollee::withTrashed()->where('employee_id', $employeeId)->where('enrollment_id', $currentEnrollmentId);

                if ($enrolleeData['birth_date'] !== null) {
                    $principalQuery = $principalQuery->where('birth_date', $enrolleeData['birth_date']);
                }

                $enrolleeData['status'] = 'ACTIVE';
                $shouldSoftDelete = false;

                if (isset($enrolleeData['employment_end_date']) && !empty($enrolleeData['employment_end_date'])) {
                    // If employment has ended, mark as inactive and prepare for soft deletion
                    $enrolleeData['status'] = 'INACTIVE';
                    $shouldSoftDelete = true;
                    $employmentEndDate = $enrolleeData['employment_end_date'];

                    // Set health insurance coverage end date
                    $healthInsuranceData['coverage_end_date'] = $employmentEndDate;

                    Log::info('Employee marked for soft deletion', [
                        'employee_id' => $employeeId,
                        'employment_end_date' => $employmentEndDate,
                        'status' => 'INACTIVE'
                    ]);
                }

                $existingPrincipal = $principalQuery->first();

                if ($existingPrincipal) {
                    if (method_exists($existingPrincipal, 'trashed') && $existingPrincipal->trashed()) {

                        // If currently soft deleted but should be active, restore it
                        if (!$shouldSoftDelete) {
                            $existingPrincipal->restore();
                        }

                        // Always update if restoring from trash
                        $existingPrincipal->update($enrolleeData);

                        Log::info('Restored and updated principal', [
                            'employee_id' => $employeeId,
                            'principal_id' => $existingPrincipal->id
                        ]);
                    } else {
                        // Check for changes before updating existing active record
                        $hasChanges = false;
                        $changes = [];

                        Log::info('Comparing enrollee data for changes', [
                            'employee_id' => $employeeId,
                            'principal_id' => $existingPrincipal->id
                        ]);

                        foreach ($enrolleeData as $key => $value) {
                            // Skip timestamps and internal fields
                            if (in_array($key, ['created_at', 'updated_at'])) {
                                continue;
                            }

                            $currentValue = $existingPrincipal->getAttribute($key);

                            // Normalize values for comparison
                            $normalizedCurrentValue = $this->normalizeValue($currentValue);
                            $normalizedNewValue = $this->normalizeValue($value);

                            // Handle null comparisons properly
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

                        if ($hasChanges) {
                            $existingPrincipal->update($enrolleeData);

                            Log::info('Principal updated with changes', [
                                'employee_id' => $employeeId,
                                'principal_id' => $existingPrincipal->id,
                                'changes' => $changes
                            ]);
                        } else {
                            Log::info('No changes detected for principal', [
                                'employee_id' => $employeeId,
                                'principal_id' => $existingPrincipal->id
                            ]);
                        }

                        // If should be soft deleted, do it now
                        if ($shouldSoftDelete && !$existingPrincipal->trashed()) {
                            $currentUserId = auth()->check() ? auth()->user()->id : 1; // Fallback to system user
                            $existingPrincipal->update([
                                'deleted_by' => $currentUserId
                            ]);
                            $existingPrincipal->delete(); // This will set deleted_at timestamp

                            Log::info('Existing principal soft deleted', [
                                'employee_id' => $employeeId,
                                'principal_id' => $existingPrincipal->id,
                                'deleted_by' => $currentUserId
                            ]);
                        }
                    }
                    $principal = $existingPrincipal;
                } else {
                    // Create new principal
                    $principal = Enrollee::create($enrolleeData);

                    // If should be soft deleted right after creation
                    if ($shouldSoftDelete) {
                        $currentUserId = auth()->check() ? auth()->user()->id : 1; // Fallback to system user
                        $principal->update([
                            'deleted_by' => $currentUserId
                        ]);
                        $principal->delete(); // This will set deleted_at timestamp

                        Log::info('New principal soft deleted', [
                            'employee_id' => $employeeId,
                            'principal_id' => $principal->id,
                            'deleted_by' => $currentUserId
                        ]);
                    }
                }

                $principalMap[$employeeId] = $principal;

                // Health insurance for principal
                if (!empty($healthInsuranceData)) {

                    $healthInsuranceData['principal_id'] = $principal->id;

                    $filtered = array_intersect_key($healthInsuranceData, array_flip($insuranceFields));

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
                            $filtered[$dateField] = $this->sanitizeDate($filtered[$dateField]);
                            Log::info("Sanitized insurance date field", [
                                'field' => $dateField,
                                'original' => $originalDate,
                                'sanitized' => $filtered[$dateField]
                            ]);
                        }
                    }

                    $filtered = $this->uppercaseStrings($filtered);

                    // Compare all provided insurance fields for a match
                    $insuranceQuery = HealthInsurance::query();

                    $insuranceQuery->where('principal_id', $principal->id);

                    $existingInsurance = $insuranceQuery->first();

                    if ($existingInsurance) {
                        // Check for changes before updating health insurance
                        $hasInsuranceChanges = false;
                        $insuranceChanges = [];

                        foreach ($filtered as $key => $value) {
                            // Skip timestamps and internal fields
                            if (in_array($key, ['created_at', 'updated_at', 'id'])) {
                                continue;
                            }

                            $currentValue = $existingInsurance->getAttribute($key);

                            // Normalize values for comparison
                            $normalizedCurrentValue = $this->normalizeValue($currentValue);
                            $normalizedNewValue = $this->normalizeValue($value);

                            // Handle null comparisons properly
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
                            $existingInsurance->update($filtered);

                            Log::info('Health insurance updated with changes', [
                                'employee_id' => $employeeId,
                                'principal_id' => $principal->id,
                                'insurance_id' => $existingInsurance->id,
                                'changes' => $insuranceChanges
                            ]);
                        } else {
                            Log::info('No changes detected for health insurance', [
                                'employee_id' => $employeeId,
                                'principal_id' => $principal->id,
                                'insurance_id' => $existingInsurance->id
                            ]);
                        }
                    } else {
                        HealthInsurance::create($filtered);

                        Log::info('New health insurance created', [
                            'employee_id' => $employeeId,
                            'principal_id' => $principal->id
                        ]);
                    }
                }
            }

            DB::commit();
            return response()->json(['message' => 'Import successful'], 200);
        } catch (\Exception $e) {

            DB::rollBack();
            return response()->json(['message' => 'Import failed', 'error' => $e->getMessage()], 500);
        }
    }
}
