<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;


use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Dependent;
use Modules\ClientMasterlist\App\Models\HealthInsurance;

use App\Http\Traits\DateSanitizer;
use App\Http\Traits\UppercaseInput;

use Illuminate\Support\Facades\DB;

class ImportEnrolleeController extends Controller
{
    use UppercaseInput, DateSanitizer;

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
                    $value = strtoupper(trim($healthInsuranceData['is_company_paid']));
                    $healthInsuranceData['is_company_paid'] = ($value === 'YES') ? 1 : 0;
                } else {
                    $healthInsuranceData['is_company_paid'] = 0; // Default to 0 if not specified
                }

                // Convert is_renewal from 'Yes'/'No' to 1/0 if present
                if (isset($healthInsuranceData['is_renewal'])) {
                    $value = strtoupper(trim($healthInsuranceData['is_renewal']));
                    $healthInsuranceData['is_renewal'] = ($value === 'YES') ? 1 : 0;

                    if ($healthInsuranceData['is_renewal']) {
                        $enrolleeData['enrollment_status'] = 'FOR-RENEWAL';
                    }
                } else {
                    $healthInsuranceData['is_renewal'] = 0; // Default to 0 if not specified
                }

                if ($relation === 'PRINCIPAL' || $relation === 'EMPLOYEE') {
                    // Check for existing principal by employee_id and birth_date
                    $enrolleeData['enrollment_id'] = $enrollmentId;
                    if (isset($enrolleeData['birth_date'])) {
                        $enrolleeData['birth_date'] = $this->sanitizeDate($enrolleeData['birth_date']);
                        $birthDate = $enrolleeData['birth_date'];
                    } else {
                        $birthDate = null;
                    }
                    $enrolleeData = $this->uppercaseStrings($enrolleeData);
                    $principalQuery = Enrollee::withTrashed()->where('employee_id', $employeeId);
                    if ($birthDate !== null) {
                        $principalQuery = $principalQuery->where('birth_date', $birthDate);
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
                        ];
                        foreach ($dateFields as $dateField) {
                            if (isset($filtered[$dateField])) {
                                $filtered[$dateField] = $this->sanitizeDate($filtered[$dateField]);
                            }
                        }
                        $filtered = $this->uppercaseStrings($filtered);
                        // Compare all provided insurance fields for a match
                        $insuranceQuery = HealthInsurance::query();

                        //foreach ($filtered as $key => $value) {
                        $insuranceQuery->where('principal_id', $principal->id);
                        //}

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
                    $value = strtoupper(trim($healthInsuranceData['is_company_paid']));
                    $healthInsuranceData['is_company_paid'] = ($value === 'YES') ? 1 : 0;
                } else {
                    $healthInsuranceData['is_company_paid'] = 1; // Default to 0 if not specified
                }
                // Convert is_renewal from 'Yes'/'No' to 1/0 if present
                if (isset($healthInsuranceData['is_renewal'])) {
                    $value = strtoupper(trim($healthInsuranceData['is_renewal']));
                    $healthInsuranceData['is_renewal'] = ($value === 'YES') ? 1 : 0;

                    if ($healthInsuranceData['is_renewal']) {
                        $enrolleeData['enrollment_status'] = 'FOR-RENEWAL';
                    }
                } else {
                    $healthInsuranceData['is_renewal'] = 0; // Default to 0 if not specified
                }

                if ($relation !== 'PRINCIPAL' && $relation !== 'EMPLOYEE') {
                    $principal = $principalMap[$employeeId] ?? null;
                    if ($principal) {
                        $enrolleeData['principal_id'] = $principal->id;
                        // Set principal_employee_id using principal's employee_id
                        $enrolleeData['principal_employee_id'] = $principal->employee_id;
                        if (isset($enrolleeData['birth_date'])) {
                            $enrolleeData['birth_date'] = $this->sanitizeDate($enrolleeData['birth_date']);
                        }
                        $enrolleeData = $this->uppercaseStrings($enrolleeData);

                        // Check for existing dependent by employee_id and birth_date
                        $birthDate = $enrolleeData['birth_date'] ?? null;
                        $dependentQuery = Dependent::withTrashed()->where('principal_id',  $principal->id);
                        if ($birthDate !== null) {
                            $dependentQuery = $dependentQuery->where('birth_date', $birthDate);
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
                            ];
                            foreach ($dateFields as $dateField) {
                                if (isset($filtered[$dateField])) {
                                    $filtered[$dateField] = $this->sanitizeDate($filtered[$dateField]);
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
}
