<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Enrollment;
use App\Http\Traits\UppercaseInput;
use App\Models\Company;

class ExportEnrolleesController extends Controller
{
    use UppercaseInput;

    // =========================================================================
    // EXPORT CONFIGURATION
    // =========================================================================

    private const EXPORT_CONFIGS = [
        'DEFAULT' => [
            'columns' => [
                'enrollment_status', 'relation', 'premium', 'employee_id', 'first_name', 
                'last_name', 'middle_name', 'suffix', 'birth_date', 'marital_status', 
                'gender', 'email1', 'phone1', 'department', 'position', 'effective_date'
            ],
            'labels' => [
                'remarks' => 'Remarks', 'reason_for_skipping' => 'Reason for Skipping',
                'attachment_for_skip_hierarchy' => 'Attachment for Skip Hierarchy',
                'effective_date' => 'Effective Date', 'required_document' => 'Required Document',
                'enrollment_status' => 'Enrollment Status', 'relation' => 'Relation',
                'employee_id' => 'Employee ID', 'first_name' => 'First Name',
                'last_name' => 'Last Name', 'middle_name' => 'Middle Name',
                'suffix' => 'Suffix', 'birth_date' => 'Birth Date', 'gender' => 'Gender',
                'marital_status' => 'Marital Status', 'email1' => 'Email 1', 'email2' => 'Email 2',
                'phone1' => 'Phone 1', 'phone2' => 'Phone 2', 'address' => 'Address',
                'department' => 'Department', 'position' => 'Position',
                'employment_start_date' => 'Employment Start Date', 'employment_end_date' => 'Employment End Date',
                'notes' => 'Notes', 'status' => 'Status', 'plan' => 'Plan', 'premium' => 'Premium',
                'principal_mbl' => 'Principal MBL', 'principal_room_and_board' => 'Principal Room and Board',
                'dependent_mbl' => 'Dependent MBL', 'dependent_room_and_board' => 'Dependent Room and Board',
                'is_renewal' => 'Is Renewal', 'is_company_paid' => 'Is Company Paid',
                'coverage_start_date' => 'Coverage Start Date', 'coverage_end_date' => 'Coverage End Date',
                'certificate_number' => 'Certificate Number', 'certificate_date_issued' => 'Certificate Date Issued'
            ]
        ],
        'MAXI-SCVP' => [
            'columns' => [
                'maxicare_account_code', 'maxicare_employee_id', 'maxicare_first_name',
                'maxicare_last_name', 'maxicare_middle_name', 'maxicare_extension',
                'maxicare_gender', 'maxicare_member_type', 'maxicare_relation',
                'maxicare_address_line_1', 'maxicare_city', 'maxicare_province',
                'maxicare_civil_status', 'maxicare_birth_date', 'maxicare_mobile_no',
                'maxicare_email', 'maxicare_effective_date', 'maxicare_philhealth',
                'maxicare_plan_code'
            ],
            'labels' => [
                'remarks' => 'Remarks', 'reason_for_skipping' => 'Reason for Skipping',
                'attachment_for_skip_hierarchy' => 'Attachment for Skip Hierarchy',
                'maxicare_account_code' => 'Account Code', 'maxicare_employee_id' => 'Employee No',
                'maxicare_first_name' => 'First Name', 'maxicare_last_name' => 'Last Name',
                'maxicare_middle_name' => 'Middle Name', 'maxicare_extension' => 'Extension',
                'maxicare_gender' => 'Gender', 'maxicare_member_type' => 'Member Type',
                'maxicare_relation' => 'Relationship', 'maxicare_address_line_1' => 'Address Line 1',
                'maxicare_city' => 'City', 'maxicare_province' => 'Province',
                'maxicare_civil_status' => 'Civil Status', 'maxicare_birth_date' => 'Birth Date',
                'maxicare_mobile_no' => 'Mobile No', 'maxicare_email' => 'Email',
                'maxicare_effective_date' => 'Effective Date', 'maxicare_philhealth' => 'PhilHealth',
                'maxicare_plan_code' => 'Plan Code'
            ]
        ],
        'MAXI-ACVP' => [
            'columns' => [
                'maxicare_employee_id', 'maxicare_last_name', 'maxicare_first_name',
                'maxicare_middle_name', 'maxicare_extension', 'maxicare_gender',
                'maxicare_street', 'maxicare_city', 'maxicare_province', 'maxicare_postal_code',
                'maxicare_email', 'maxicare_mobile_no', 'maxicare_member_type',
                'maxicare_birth_date', 'relation', 'marital_status',
                'maxicare_effective_date', 'maxicare_date_hired', 'maxicare_date_regularization',
                'maxicare_is_philhealth_member', 'maxicare_philhealth_conditions',
                'maxicare_position', 'maxicare_plan_type', 'maxicare_branch_name',
                'maxicare_philhealth_no', 'maxicare_senior_citizen_id_no',
                'maxicare_client_remarks', 'maxicare_phic'
            ],
            'labels' => [
                'remarks' => 'Remarks', 'reason_for_skipping' => 'Reason for Skipping',
                'attachment_for_skip_hierarchy' => 'Attachment for Skip Hierarchy',
                'maxicare_employee_id' => 'EmpNo', 'maxicare_last_name' => 'LastName',
                'maxicare_first_name' => 'FirstName', 'maxicare_middle_name' => 'MiddleName',
                'maxicare_extension' => 'Extension', 'maxicare_gender' => 'Gender',
                'maxicare_street' => 'Street', 'maxicare_city' => 'City',
                'maxicare_province' => 'Province', 'maxicare_postal_code' => 'ZipCode',
                'maxicare_email' => 'Email', 'maxicare_mobile_no' => 'MobileNo',
                'maxicare_member_type' => 'MemberType', 'maxicare_birth_date' => 'BirthDate',
                'relation' => 'RelationshipID', 'marital_status' => 'CivilStat',
                'maxicare_effective_date' => 'EffectiveDate', 'maxicare_date_hired' => 'DateHired',
                'maxicare_date_regularization' => 'RegDate',
                'maxicare_is_philhealth_member' => 'If Enrollee is a Philhealth member',
                'maxicare_philhealth_conditions' => 'Philhealth conditions',
                'maxicare_position' => 'POSITION', 'maxicare_plan_type' => 'PLAN TYPE',
                'maxicare_branch_name' => 'BRANCH NAME', 'maxicare_philhealth_no' => 'PHILHEALTH NO.',
                'maxicare_senior_citizen_id_no' => 'SENIOR CITIZEN ID. NO.',
                'maxicare_client_remarks' => 'Client Remarks', 'maxicare_phic' => 'PHIC'
            ]
        ]
    ];

    private const INSURANCE_FIELDS = [
        'plan', 'premium', 'principal_mbl', 'principal_room_and_board',
        'dependent_mbl', 'dependent_room_and_board', 'is_renewal', 'is_company_paid',
        'coverage_start_date', 'coverage_end_date', 'certificate_number', 'certificate_date_issued'
    ];

    private const RELATION_CODE_MAP = [
        'SPOUSE' => 'SP', 'CHILD' => 'C', 'PARENT' => 'PR', 'SIBLING' => 'SL',
        'DOMESTIC PARTNER' => 'O', 'COMMON-LAW PARTNER' => 'O'
    ];

    private const CIVIL_STATUS_CODE_MAP = [
        'SINGLE' => 'S', 'SINGLE PARENT' => 'SP', 'MARRIED' => 'M'
    ];

    // =========================================================================
    // MAIN EXPORT METHODS
    // =========================================================================

    public function exportEnrollees(Request $request)
    {
        $filters = $this->extractFilters($request);
        $withDependents = $request->query('with_dependents', false);
        $isForAttachment = $request->query('for_attachment', false);

        $enrollees = $this->buildBaseQuery($filters)->get();
        $exportType = $this->getExportType($filters['enrollment_id']);
        
        $columns = $this->determineColumns($request, $exportType, $isForAttachment);
        $columns = $this->processColumns($columns, $enrollees, $exportType);
        
        // Use DEFAULT labels when use_selected_columns is checked
        $useDefaultLabels = (bool) $request->query('use_selected_columns');
        $headers = $this->generateHeaders($columns, $exportType, $useDefaultLabels);
        // Use DEFAULT column values when use_selected_columns is checked
        $useDefaultValues = (bool) $request->query('use_selected_columns');
        $rows = $this->generateRows($enrollees, $columns, $withDependents, $exportType, $useDefaultValues);
        $csv = $this->generateCsv($headers, $rows);

        if ($isForAttachment && $filters['enrollment_status'] === 'SUBMITTED') {
            $this->updateSubmittedEnrollees($enrollees);
        }

        return $this->createCsvResponse($csv, $filters['enrollment_status'], $enrollees);
    }

    public function exportEnrolleesForAttachment(Request $request)
    {
        $request->merge(['for_attachment' => true]);
        return $this->exportEnrollees($request);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function extractFilters(Request $request): array
    {
        return [
            'use_selected_columns' => $request->query('use_selected_columns'),
            'enrollment_id' => $request->query('enrollment_id'),
            'export_enrollment_type' => $request->query('export_enrollment_type'),
            'enrollment_status' => $request->query('enrollment_status'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];
    }

    private function getExportType($enrollmentId): string
    {
        if (empty($enrollmentId)) return 'DEFAULT';
        
        $enrollment = Enrollment::find($enrollmentId);
        return $enrollment?->export_type ? strtoupper($enrollment->export_type) : 'DEFAULT';
    }

    private function isCustomExportType(string $exportType): bool
    {
        return in_array($exportType, ['MAXI-SCVP', 'MAXI-ACVP']);
    }

    private function getConfig(string $exportType): array
    {
        return self::EXPORT_CONFIGS[$exportType] ?? self::EXPORT_CONFIGS['DEFAULT'];
    }

    private function determineColumns(Request $request, string $exportType, bool $isForAttachment): array
    {
        // If use_selected_columns is checked, ALWAYS use DEFAULT columns regardless of export type
        if ($request->query('use_selected_columns')) {
            return self::EXPORT_CONFIGS['DEFAULT']['columns'];
        }

        $config = $this->getConfig($exportType);
        
        if ($this->isCustomExportType($exportType) || $isForAttachment) {
            return $config['columns'];
        }

        return $request->query('columns', []);
    }

    private function buildBaseQuery(array $filters = [])
    {
        $relationships = $this->buildRelationships($filters);
        $query = Enrollee::with($relationships);

        $this->applyFilters($query, $filters);

        return $query;
    }

    private function buildRelationships(array $filters): array
    {
        $relationships = ['healthInsurance', 'enrollment.insuranceProvider'];
        
        if (($filters['enrollment_status'] ?? '') === 'APPROVED') {
            // For APPROVED status, only load approved dependents
            $relationships['dependents'] = fn($query) => $this->applyDependentFilters($query, $filters, ['APPROVED']);
        } else {
            // For all other statuses, load ALL active dependents (including SKIPPED/OVERAGE)
            // This is critical for detecting skip hierarchy requirements
            $relationships['dependents'] = function($query) use ($filters) {
                $query->where('status', 'ACTIVE')
                      ->with(['healthInsurance', 'attachmentForSkipHierarchy', 'attachmentForRequirement', 'requiredDocuments', 'attachments']);
                
                // Only apply date filters if needed, but don't filter by enrollment_status
                if (isset($filters['date_from']) || isset($filters['date_to'])) {
                    $query->whereHas('healthInsurance', function ($subQ) use ($filters) {
                        $this->applyHealthInsuranceDateFilters($subQ, $filters);
                    });
                }
                
                Log::info('Loading ALL dependents for skip detection');
            };
        }

        return $relationships;
    }

    private function applyDependentFilters($query, array $filters, ?array $statuses = null)
    {
        // IMPORTANT: Don't filter by enrollment_status here if we need to detect SKIPPED/OVERAGE
        // Only filter by status for APPROVED exports to maintain data integrity
        if ($statuses && in_array('APPROVED', $statuses)) {
            $query->whereIn('enrollment_status', $statuses);
        }
        // For other cases, load ALL dependents so we can properly detect SKIPPED/OVERAGE
        
        $query->where('status', 'ACTIVE')
              ->with(['healthInsurance', 'attachmentForSkipHierarchy', 'attachmentForRequirement', 'requiredDocuments', 'attachments']);

        if (isset($filters['date_from']) || isset($filters['date_to'])) {
            $query->whereHas('healthInsurance', function ($subQ) use ($filters) {
                $this->applyHealthInsuranceDateFilters($subQ, $filters);
            });
        }

        Log::info('Applied dependent filters', [
            'statuses' => $statuses,
            'will_filter_enrollment_status' => $statuses && in_array('APPROVED', $statuses)
        ]);
    }

    private function applyHealthInsuranceDateFilters($query, array $filters)
    {
        if (isset($filters['date_from'])) {
            $query->where(function ($dateQ) use ($filters) {
                $dateQ->where('coverage_start_date', '<=', $filters['date_from'])
                      ->orWhereNull('coverage_start_date');
            })->where(function ($dateQ) use ($filters) {
                $dateQ->where('certificate_date_issued', '<=', $filters['date_from'])
                      ->orWhereNull('certificate_date_issued');
            });
        }

        if (isset($filters['date_to'])) {
            $query->where(function ($dateQ) use ($filters) {
                $dateQ->where('coverage_end_date', '>=', $filters['date_to'])
                      ->orWhereNull('coverage_end_date');
            });
        }
    }

    private function applyFilters($query, array $filters)
    {
        if (!empty($filters['enrollment_id'])) {
            $query->where('enrollment_id', $filters['enrollment_id']);
        }

        if (($filters['enrollment_status'] ?? '') !== 'APPROVED') {
            $query->where('status', 'ACTIVE')->whereNull('deleted_at');
        }

        if (isset($filters['enrollment_status']) || isset($filters['export_enrollment_type'])) {
            $this->applyEnrollmentStatusFilter($query, $filters);
        }

        $this->applyDateFilters($query, $filters);
    }

    private function applyDateFilters($query, array $filters)
    {
        $enrollmentStatus = $filters['enrollment_status'] ?? '';
        
        if (isset($filters['date_from'])) {
            if ($enrollmentStatus === 'APPROVED') {
                $query->whereHas('healthInsurance', fn($subQ) => $this->applyHealthInsuranceDateFilters($subQ, $filters));
            } else {
                $query->where('updated_at', '>=', $filters['date_from'] . ' 00:00:00');
            }
        }

        if (isset($filters['date_to'])) {
            if ($enrollmentStatus === 'APPROVED') {
                $query->whereHas('healthInsurance', fn($subQ) => $this->applyHealthInsuranceDateFilters($subQ, $filters));
            } else {
                $query->where('updated_at', '<=', $filters['date_to'] . ' 23:59:59');
            }
        }
    }

    private function applyEnrollmentStatusFilter($query, array $filters)
    {
        $enrollmentStatus = $filters['enrollment_status'] ?? null;
        $exportType = $filters['export_enrollment_type'] ?? null;

        if ($exportType === 'RENEWAL') {
            $query->whereHas('healthInsurance', fn($subQ) => $subQ->where('is_renewal', true));
            
            if ($enrollmentStatus === 'PENDING') {
                $query->where('enrollment_status', 'FOR-RENEWAL');
            } elseif ($enrollmentStatus) {
                $query->where('enrollment_status', $enrollmentStatus);
            }
        } elseif ($exportType === 'REGULAR') {
            $query->whereHas('healthInsurance', fn($subQ) => $subQ->where('is_renewal', false));
            
            if ($enrollmentStatus) {
                $query->where('enrollment_status', $enrollmentStatus);
            }
        } else {
            // Handle ALL export type
            if ($enrollmentStatus === 'PENDING') {
                $query->whereIn('enrollment_status', ['FOR-RENEWAL', 'PENDING']);
            } elseif ($enrollmentStatus === 'APPROVED') {
                $query->whereIn('enrollment_status', ['APPROVED', 'RESIGNED']);
            } elseif ($enrollmentStatus) {
                $query->where('enrollment_status', $enrollmentStatus);
            }
        }
    }

    private function processColumns(array $columns, $enrollees, string $exportType): array
    {
        $columns = $this->normalizeColumns($columns);

        // Always check for skip/overage conditions first for ALL export types
        $checks = $this->analyzeEnrollees($enrollees);
        
        Log::info('Processing columns', [
            'export_type' => $exportType,
            'initial_columns' => $columns,
            'checks' => $checks
        ]);
        
        // Always add skip-related columns if there are any skipped/overage dependents
        // This applies to ALL export types for safety
        if ($checks['hasSkippedOrOverage']) {
            Log::info('Adding skip-related columns for safety');
            $skipColumns = ['remarks', 'reason_for_skipping', 'attachment_for_skip_hierarchy'];
            foreach ($skipColumns as $skipCol) {
                if (!in_array($skipCol, $columns)) {
                    array_unshift($columns, $skipCol);
                    Log::info('Added skip column: ' . $skipCol);
                }
            }
        }

        // Add other dynamic columns only for non-custom export types
        if (!$this->isCustomExportType($exportType)) {
            $columns = $this->addRequiredColumns($columns);
            $columns = $this->addDynamicColumns($columns, $enrollees, $checks);
        } else {
            // For custom export types, still add required document column if needed
            if ($checks['hasRequiredDocument'] && !in_array('required_document', $columns)) {
                $columns[] = 'required_document';
            }
        }

        Log::info('Final columns after processing', ['columns' => $columns]);
        return $columns;
    }

    private function normalizeColumns($columns): array
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        } elseif (is_array($columns)) {
            $flatColumns = [];
            array_walk_recursive($columns, function ($value) use (&$flatColumns) {
                if (is_string($value) || is_numeric($value)) {
                    $flatColumns[] = (string)$value;
                }
            });
            $columns = $flatColumns;
        } else {
            $columns = [];
        }

        return array_values(array_unique(array_filter($columns, fn($v) => is_string($v) && trim($v) !== '')));
    }

    private function addRequiredColumns(array $columns): array
    {
        $requiredColumns = ['effective_date', 'enrollment_status', 'relation'];
        
        foreach ($requiredColumns as $required) {
            if (!in_array($required, $columns)) {
                $columns[] = $required;
            }
        }

        return $columns;
    }

    private function addDynamicColumns(array $columns, $enrollees, ?array $checks = null): array
    {
        // Use existing checks if provided, otherwise analyze enrollees
        if ($checks === null) {
            $checks = $this->analyzeEnrollees($enrollees);
        }

        if ($checks['hasPremium'] && !in_array('premium', $columns)) {
            $relationIndex = array_search('relation', $columns);
            if ($relationIndex !== false) {
                array_splice($columns, $relationIndex + 1, 0, 'premium');
            } else {
                $columns[] = 'premium';
            }
        }

        if ($checks['hasRequiredDocument'] && !in_array('required_document', $columns)) {
            $columns[] = 'required_document';
        }

        // Skip-related columns are now handled in processColumns() for all export types
        
        return $columns;
    }

    private function analyzeEnrollees($enrollees): array
    {
        $checks = [
            'hasSkippedOrOverage' => false,
            'hasRequiredDocument' => false,
            'hasPremium' => false
        ];

        Log::info('Starting analyzeEnrollees', [
            'total_enrollees' => count($enrollees)
        ]);

        foreach ($enrollees as $enrollee) {
            Log::info('Processing enrollee', [
                'enrollee_id' => $enrollee->id,
                'employee_id' => $enrollee->employee_id,
                'enrollment_status' => $enrollee->enrollment_status,
                'dependents_count' => $enrollee->dependents ? count($enrollee->dependents) : 0,
                'dependents_loaded' => $enrollee->relationLoaded('dependents')
            ]);

            if ($enrollee->dependents && count($enrollee->dependents) > 0) {
                // Check for premium calculation conditions
                if (!$checks['hasPremium']) {
                    $checks['hasPremium'] = $this->shouldCalculatePremium($enrollee);
                }

                foreach ($enrollee->dependents as $dependent) {
                    // Enhanced debug logging
                    Log::info('Checking dependent details', [
                        'enrollee_id' => $enrollee->id,
                        'dependent_id' => $dependent->id,
                        'dependent_name' => $dependent->first_name . ' ' . $dependent->last_name,
                        'dependent_relation' => $dependent->relation,
                        'enrollment_status' => $dependent->enrollment_status,
                        'status' => $dependent->status,
                        'is_skipped_or_overage' => in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])
                    ]);

                    if (!$checks['hasSkippedOrOverage'] && in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                        $checks['hasSkippedOrOverage'] = true;
                        Log::info('FOUND SKIPPED/OVERAGE DEPENDENT - WILL ADD SKIP COLUMNS', [
                            'dependent_id' => $dependent->id,
                            'enrollment_status' => $dependent->enrollment_status
                        ]);
                    }

                    if (!$checks['hasRequiredDocument']) {
                        $checks['hasRequiredDocument'] = $this->hasRequiredDocuments($dependent);
                    }

                    // Don't break early - continue checking all dependents for debugging
                }
            }
        }

        Log::info('Final analysis results', $checks);
        return $checks;
    }

    private function shouldCalculatePremium($enrollee): bool
    {
        $hasMaxDependents = isset($enrollee->max_dependents) && $enrollee->max_dependents > 0;
        $hasPremiumRestriction = $enrollee->enrollment && !empty($enrollee->enrollment->premium_restriction);
        $hasPremiumComputation = $enrollee->enrollment && !empty($enrollee->enrollment->premium_computation);
        
        if (!($hasMaxDependents || $hasPremiumRestriction || $hasPremiumComputation)) {
            return false;
        }

        $hasPremiumFromEnrollment = $enrollee->enrollment && isset($enrollee->enrollment->premium) && $enrollee->enrollment->premium > 0;
        $hasPremiumFromInsurance = $enrollee->healthInsurance && isset($enrollee->healthInsurance->premium) && $enrollee->healthInsurance->premium > 0;
        
        return $hasPremiumFromEnrollment || $hasPremiumFromInsurance;
    }

    private function hasRequiredDocuments($dependent): bool
    {
        if ($dependent->requiredDocuments && $dependent->requiredDocuments->count() > 0) {
            return true;
        }

        return method_exists($dependent, 'attachmentForRequirement') 
               && $dependent->attachmentForRequirement 
               && $dependent->attachmentForRequirement->file_path;
    }

    private function generateHeaders(array $columns, string $exportType, bool $useDefaultLabels = false): array
    {
        // Use DEFAULT labels if specifically requested (when use_selected_columns is checked)
        $config = $useDefaultLabels ? self::EXPORT_CONFIGS['DEFAULT'] : $this->getConfig($exportType);
        return array_map(fn($col) => $config['labels'][$col] ?? $col, $columns);
    }

    private function generateRows($enrollees, array $columns, bool $withDependents, string $exportType, bool $useDefaultValues = false): array
    {
        $rows = [];
        $colCount = count($columns);
        // Force use of DEFAULT values when use_selected_columns is checked
        $isCustom = $useDefaultValues ? false : $this->isCustomExportType($exportType);

        foreach ($enrollees as $enrollee) {
            // Add principal row
            $row = $this->generateEntityRow($enrollee, $columns, $withDependents, true, null, $isCustom);
            $rows[] = $this->normalizeRowLength($row, $colCount);

            // Add dependent rows if needed
            if ($withDependents && $enrollee->dependents && count($enrollee->dependents) > 0) {
                foreach ($enrollee->dependents as $dependent) {
                    $depRow = $this->generateEntityRow($dependent, $columns, $withDependents, false, $enrollee, $isCustom);
                    $rows[] = $this->normalizeRowLength($depRow, $colCount);
                }
            }
        }

        return $rows;
    }

    private function generateEntityRow($entity, array $columns, bool $withDependents, bool $isPrincipal, $principal, bool $isCustom): array
    {
        return array_map(function ($col) use ($entity, $withDependents, $isPrincipal, $principal, $isCustom) {
            return $isCustom 
                ? $this->getMaxicareColumnValue($col, $entity, $isPrincipal, $principal)
                : $this->getColumnValue($col, $entity, $withDependents, $isPrincipal, $principal);
        }, $columns);
    }

    private function normalizeRowLength(array $row, int $expectedLength): array
    {
        if (count($row) < $expectedLength) {
            return array_pad($row, $expectedLength, '');
        } elseif (count($row) > $expectedLength) {
            return array_slice($row, 0, $expectedLength);
        }
        return $row;
    }

    // =========================================================================
    // COLUMN VALUE METHODS
    // =========================================================================

    private function getColumnValue(string $column, $entity, bool $withDependents, bool $isPrincipal, $principal): string
    {
        return match ($column) {
            'remarks' => $this->getSkipRemarks($entity, $isPrincipal),
            'reason_for_skipping' => $this->getReasonForSkipping($entity, $isPrincipal),
            'attachment_for_skip_hierarchy' => $this->getSkipAttachment($entity, $isPrincipal),
            'effective_date' => $this->getEffectiveDate(),
            'enrollment_status' => $this->getEnrollmentStatus($entity, $isPrincipal, $principal),
            'relation' => $this->getRelation($entity, $withDependents, $isPrincipal),
            'department', 'position' => $this->getFromPrincipalOrEntity($entity, $principal, $isPrincipal, $column),
            'is_renewal', 'is_company_paid' => $this->getBooleanFromHealthInsurance($entity, $principal, $isPrincipal, $column),
            'premium' => $this->calculatePremium($entity, $isPrincipal, $principal),
            'required_document' => $this->getRequiredDocuments($entity, $isPrincipal),
            default => $this->getDefaultColumnValue($column, $entity)
        };
    }

    private function getMaxicareColumnValue(string $column, $entity, bool $isPrincipal, $principal): string
    {
        // Handle common columns first (including skip-related columns)
        $commonValue = $this->getCommonColumnValue($column, $entity, $isPrincipal, $principal);
        if ($commonValue !== null) return $commonValue;

        $enrollment = $this->getEnrollmentReference($entity, $isPrincipal, $principal);

        return match ($column) {
            'maxicare_account_code' => $enrollment->account_code ?? '',
            'maxicare_employee_id' => $isPrincipal ? ($entity->employee_id ?? '') : ($principal->employee_id ?? ''),
            'maxicare_first_name' => $entity->first_name ?? '',
            'maxicare_last_name' => $entity->last_name ?? '',
            'maxicare_middle_name' => $entity->middle_name ?? '',
            'maxicare_extension' => $entity->suffix ?? '',
            'maxicare_member_type' => $isPrincipal ? 'P' : 'D',
            'maxicare_gender' => $entity->gender === 'MALE' ? 'M' : 'F',
            'maxicare_relation' => $this->getRelationCode($entity->relation ?? ''),
            'maxicare_address_line_1', 'maxicare_address_line_2', 'maxicare_city', 'maxicare_province', 'maxicare_postal_code', 'maxicare_street' => $this->getAddressPart($column, $entity->address ?? ''),
            'maxicare_civil_status' => $this->getCivilStatusCode($entity->marital_status ?? ''),
            'maxicare_birth_date' => $entity->birth_date ?? '',
            'maxicare_mobile_no' => $entity->phone1 ?? '',
            'maxicare_email' => $entity->email1 ?? '',
            'maxicare_effective_date' => $this->getEffectiveDate(),
            'maxicare_date_hired', 'maxicare_date_regularization' => $entity->employment_start_date ?? '',
            'maxicare_philhealth' => 'R',
            'maxicare_plan_code' => $this->getPlanCode($entity, $isPrincipal, $principal, $enrollment),
            'maxicare_card_issuance' => 'Y',
            'maxicare_is_philhealth_member' => 'YES',
            'maxicare_philhealth_conditions' => '',
            'maxicare_position' => $this->getPrincipalPosition($entity, $isPrincipal, $principal),
            'maxicare_plan_type' => $this->getPlanType($entity, $isPrincipal, $principal, $enrollment),
            'maxicare_branch_name' => $this->getBranchName($entity, $isPrincipal, $principal, $enrollment),
            'maxicare_philhealth_no' => '',
            'maxicare_senior_citizen_id_no' => '',
            'maxicare_client_remarks' => '',
            'maxicare_phic' => '',
            'premium' => $this->calculatePremium($entity, $isPrincipal, $principal),
            'required_document' => $this->getRequiredDocuments($entity, $isPrincipal),
            default => $this->getDefaultColumnValue($column, $entity)
        };
    }

    private function getCommonColumnValue(string $column, $entity, bool $isPrincipal, $principal): ?string
    {
        return match ($column) {
            'remarks' => $this->getSkipRemarks($entity, $isPrincipal),
            'reason_for_skipping' => $this->getReasonForSkipping($entity, $isPrincipal),
            'attachment_for_skip_hierarchy' => $this->getSkipAttachment($entity, $isPrincipal),
            default => null
        };
    }

    // =========================================================================
    // HELPER METHODS FOR COLUMN VALUES
    // =========================================================================

    private function getSkipRemarks($entity, bool $isPrincipal): string
    {
        if (!$isPrincipal && $this->isSkippedOrOverage($entity)) {
            return $entity->enrollment_status === 'SKIPPED'
                ? 'DO NOT ENROLL, SKIPPED HIERARCHY'
                : 'DO NOT ENROLL, OVERAGE';
        }
        return '';
    }

    private function getReasonForSkipping($entity, bool $isPrincipal): string
    {
        return (!$isPrincipal && $this->isSkippedOrOverage($entity)) 
            ? ($entity->healthInsurance->reason_for_skipping ?? '') 
            : '';
    }

    private function getSkipAttachment($entity, bool $isPrincipal): string
    {
        return (!$isPrincipal && $this->isSkippedOrOverage($entity)) 
            ? ($entity->attachmentForSkipHierarchy->file_path ?? '') 
            : '';
    }

    private function isSkippedOrOverage($entity): bool
    {
        return in_array($entity->enrollment_status, ['SKIPPED', 'OVERAGE']);
    }

    private function getEffectiveDate(): string
    {
        return date('Y-m-d', strtotime('first day of next month'));
    }

    private function getRelation($entity, bool $withDependents, bool $isPrincipal): string
    {
        return $withDependents 
            ? ($isPrincipal ? 'PRINCIPAL' : ($entity->relation ?? 'PRINCIPAL')) 
            : 'PRINCIPAL';
    }

    private function getEnrollmentStatus($entity, bool $isPrincipal, $principal): string
    {
        if (!$isPrincipal && $principal) {
            $principalStatus = $principal->enrollment_status;
            $dependentStatus = $entity->enrollment_status;
            
            // CRITICAL: Never change SKIPPED or OVERAGE statuses - they must remain as-is for safety
            if (in_array($dependentStatus, ['SKIPPED', 'OVERAGE'])) {
                return $dependentStatus;
            }
            
            $statusMap = [
                'PENDING' => ['condition' => empty($dependentStatus), 'newStatus' => 'PENDING'],
                'SUBMITTED-PERSONAL-INFORMATION' => ['condition' => empty($dependentStatus), 'newStatus' => 'SUBMITTED-PERSONAL-INFORMATION'],
                'SUBMITTED' => ['condition' => true, 'newStatus' => 'FOR-APPROVAL'],
            ];

            if (isset($statusMap[$principalStatus]) && $statusMap[$principalStatus]['condition']) {
                $newStatus = $statusMap[$principalStatus]['newStatus'];
                $entity->enrollment_status = $newStatus;
                $entity->save();
                return $newStatus;
            }
        }
        return $entity->enrollment_status ?? '';
    }

    private function getFromPrincipalOrEntity($entity, $principal, bool $isPrincipal, string $field): string
    {
        return (!$isPrincipal && $principal) ? ($principal->$field ?? '') : ($entity->$field ?? '');
    }

    private function getBooleanFromHealthInsurance($entity, $principal, bool $isPrincipal, string $field): string
    {
        $source = (!$isPrincipal && $principal) ? $principal : $entity;
        return ($source->healthInsurance->$field ?? false) ? 'YES' : 'NO';
    }

    private function getRequiredDocuments($entity, bool $isPrincipal): string
    {
        if ($isPrincipal) return '';

        if ($entity->requiredDocuments && $entity->requiredDocuments->count() > 0) {
            return implode('; ', $entity->requiredDocuments->pluck('file_path')->toArray());
        }

        if (method_exists($entity, 'attachmentForRequirement') &&
            $entity->attachmentForRequirement && 
            $entity->attachmentForRequirement->file_path
        ) {
            return $entity->attachmentForRequirement->file_path;
        }

        return '';
    }

    private function getDefaultColumnValue(string $column, $entity): string
    {
        if (in_array($column, self::INSURANCE_FIELDS)) {
            return $entity->healthInsurance ? ($entity->healthInsurance->$column ?? '') : '';
        }
        return $entity->$column ?? '';
    }

    private function getEnrollmentReference($entity, bool $isPrincipal, $principal)
    {
        return $isPrincipal ? $entity->enrollment : ($principal->enrollment ?? null);
    }

    private function getRelationCode(string $relation): string
    {
        return self::RELATION_CODE_MAP[strtoupper($relation)] ?? 'P';
    }

    private function getCivilStatusCode(string $maritalStatus): string
    {
        return self::CIVIL_STATUS_CODE_MAP[strtoupper($maritalStatus)] ?? 'S';
    }

    private function getAddressPart(string $column, string $address): string
    {
        if (empty($address)) return '';

        $addressParts = array_map('trim', explode(',', $address));
        
        $fieldMap = [
            'maxicare_address_line_1' => 0,
            'maxicare_address_line_2' => 1,
            'maxicare_street' => 0,
            'maxicare_city' => 2,
            'maxicare_province' => 3,
            'maxicare_postal_code' => 4,
        ];

        $index = $fieldMap[$column] ?? 0;
        return $addressParts[$index] ?? '';
    }

    private function getPlanCode($entity, bool $isPrincipal, $principal, $enrollment): string
    {
        if ($enrollment && !empty($enrollment->plan_code)) {
            return $this->parsePlanCode($enrollment->plan_code, $isPrincipal);
        }

        if (!empty($entity->healthInsurance->plan)) {
            return $this->parsePlanCode($entity->healthInsurance->plan, $isPrincipal);
        }

        if (!$isPrincipal && $principal && !empty($principal->healthInsurance->plan)) {
            return $this->parsePlanCode($principal->healthInsurance->plan, false);
        }

        return '';
    }

    private function parsePlanCode(string $plan, bool $isPrincipal): string
    {
        $plan = trim($plan);

        if (!str_contains($plan, ',')) {
            return $plan;
        }

        $planParts = array_map('trim', explode(',', $plan));
        $partCount = count($planParts);

        if ($partCount == 2) {
            return $isPrincipal ? $planParts[0] : $planParts[1];
        } elseif ($partCount == 3) {
            return $isPrincipal ? $planParts[1] : $planParts[2];
        }

        return $planParts[0];
    }

    // =========================================================================
    // CSV GENERATION AND RESPONSE METHODS
    // =========================================================================

    private function generateCsv(array $headers, array $rows): string
    {
        $sanitize = fn($value) => '"' . str_replace('"', '""', 
            str_replace(["\r", "\n", "\t"], ' ', 
                mb_convert_encoding($value, 'UTF-8', 'UTF-8')
            )
        ) . '"';

        $csv = "\xEF\xBB\xBF"; // UTF-8 BOM
        $csv .= implode(',', array_map($sanitize, $headers)) . "\r\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map($sanitize, $row)) . "\r\n";
        }

        return $csv;
    }

    private function createCsvResponse(string $csv, ?string $enrollmentStatus, $enrollees = null)
    {
        $company = 'UNKNOWN';
        $provider = 'UNKNOWN';

        if ($enrollees && count($enrollees) > 0) {
            $firstEnrollee = $enrollees->first();

            if ($firstEnrollee->enrollment && $firstEnrollee->enrollment->company_id) {
                $companyRecord = Company::find($firstEnrollee->enrollment->company_id);
                if ($companyRecord && $companyRecord->company_code) {
                    $company = $companyRecord->company_code;
                }
            }

            if ($firstEnrollee->enrollment && $firstEnrollee->enrollment->insuranceProvider) {
                $provider = $firstEnrollee->enrollment->insuranceProvider->title ?? 'UNKNOWN';
            }
        }

        $filename = 'EXPORT_C-' . $company . '_P-' . $provider . '_S-' . ($enrollmentStatus ?: 'ALL') . '_DT-' . date('Ymd_His') . '.csv';

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    private function updateSubmittedEnrollees($enrollees): void
    {
        foreach ($enrollees as $enrollee) {
            if ($enrollee->enrollment_status === 'SUBMITTED') {
                $enrollee->enrollment_status = 'FOR-APPROVAL';
                $enrollee->save();
            }
        }
    }

    // =========================================================================
    // PREMIUM CALCULATION METHOD
    // =========================================================================

    private function calculatePremium($entity, bool $isPrincipal, $principal = null): string
    {
        // For principals, don't show premium
        if ($isPrincipal) return '0.00';
        if (!$principal) return '0.00';

        // Get base premium
        $basePremium = 0;
        if ($principal->enrollment && !empty($principal->enrollment->premium)) {
            $basePremium = floatval($principal->enrollment->premium);
        } elseif ($principal->healthInsurance && !empty($principal->healthInsurance->premium)) {
            $basePremium = floatval($principal->healthInsurance->premium);
        }

        if ($basePremium == 0) return '0.00';

        // Check if company paid
        if ($principal->healthInsurance && $principal->healthInsurance->is_company_paid) {
            return '0.00';
        }

        $premiumComputation = $principal->enrollment->premium_computation ?? null;
        $premiumRestriction = $principal->enrollment->premium_restriction ?? null;

        // Parse premium computation
        $percentMap = $this->parsePremiumComputation($premiumComputation);

        // Calculate age
        $age = $this->calculateAge($entity->birth_date);

        // Get premium based on age restrictions
        $adjustedPremium = $this->getAgeAdjustedPremium($basePremium, $premiumRestriction, $age);

        // For age > 65, return full adjusted premium
        if ($age > 65) return (string)$adjustedPremium;

        // Calculate based on dependent position
        $dependentPosition = $this->getDependentPosition($entity, $principal);
        $percent = $this->getDependentPercentage($dependentPosition, $principal->max_dependents, $percentMap);

        return (string)($adjustedPremium * ($percent / 100));
    }

    private function parsePremiumComputation(?string $premiumComputation): array
    {
        $percentMap = [];
        if (!$premiumComputation || trim($premiumComputation) === '') {
            return $percentMap;
        }

        $parts = array_map('trim', explode(',', $premiumComputation));
        foreach ($parts as $part) {
            $split = explode(':', $part);
            if (count($split) === 2 && is_numeric($split[1])) {
                $label = trim($split[0]);
                $normalizedLabel = strtoupper($label) === 'REST' ? 'REST' : $label;
                $percentMap[$normalizedLabel] = floatval($split[1]);
            }
        }

        return $percentMap;
    }

    private function calculateAge(?string $birthDate): int
    {
        if (!$birthDate) return 0;

        $birth = new \DateTime($birthDate);
        $today = new \DateTime();
        return $today->diff($birth)->y;
    }

    private function getAgeAdjustedPremium(float $basePremium, ?string $premiumRestriction, int $age): float
    {
        if (!$premiumRestriction || $age === 0) {
            return $basePremium;
        }

        $restrictions = array_map('trim', explode(',', $premiumRestriction));
        $ageThresholds = [];
        
        foreach ($restrictions as $restriction) {
            $split = explode(':', $restriction);
            if (count($split) === 2 && is_numeric($split[0]) && is_numeric($split[1])) {
                $ageThresholds[] = [
                    'age' => intval(trim($split[0])),
                    'premium' => floatval(trim($split[1]))
                ];
            }
        }
        
        // Sort by age descending
        usort($ageThresholds, fn($a, $b) => $b['age'] - $a['age']);
        
        // Find applicable premium based on age
        foreach ($ageThresholds as $threshold) {
            if ($age >= $threshold['age']) {
                return $threshold['premium'];
            }
        }

        return $basePremium;
    }

    private function getDependentPosition($entity, $principal): int
    {
        $dependentPosition = 0;
        if (!$principal->dependents) return $dependentPosition;

        foreach ($principal->dependents as $dep) {
            if (in_array($dep->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                continue;
            }
            
            $dependentPosition++;
            
            if ($dep->id === $entity->id) {
                break;
            }
        }

        return $dependentPosition;
    }

    private function getDependentPercentage(int $dependentPosition, ?int $maxDependents, array $percentMap): float
    {
        if ($maxDependents !== null && $maxDependents > 0) {
            if ($dependentPosition <= $maxDependents) {
                return $percentMap[(string)$dependentPosition] ?? $percentMap['1'] ?? 0;
            } else {
                return $percentMap['REST'] ?? $percentMap['1'] ?? 0;
            }
        }

        return $percentMap[(string)$dependentPosition] ?? $percentMap['REST'] ?? $percentMap['1'] ?? 0;
    }

    // =========================================================================
    // ACVP SPECIFIC HELPER METHODS
    // =========================================================================

    private function getPlanType($entity, bool $isPrincipal, $principal, $enrollment): string
    {
        // Get plan from enrollment or health insurance
        if ($enrollment && !empty($enrollment->plan_code)) {
            return $this->extractPlanType($enrollment->plan_code);
        }

        if (!empty($entity->healthInsurance->plan)) {
            return $this->extractPlanType($entity->healthInsurance->plan);
        }

        if (!$isPrincipal && $principal && !empty($principal->healthInsurance->plan)) {
            return $this->extractPlanType($principal->healthInsurance->plan);
        }

        return '';
    }

    private function extractPlanType(string $planData): string
    {
        // If plan data contains comma, extract the plan name (first part)
        if (str_contains($planData, ',')) {
            $planParts = array_map('trim', explode(',', $planData));
            return $planParts[0] ?? '';
        }
        
        return $planData;
    }

    private function getBranchName($entity, bool $isPrincipal, $principal, $enrollment): string
    {
        // Get branch name from enrollment or company
        if ($enrollment && !empty($enrollment->branch_name)) {
            return $enrollment->branch_name;
        }

        // Try to get from company if available
        if ($enrollment && $enrollment->company_id) {
            $company = Company::find($enrollment->company_id);
            return $company->company_name ?? '';
        }

        return '';
    }

    private function getPrincipalPosition($entity, bool $isPrincipal, $principal): string
    {
        // Always return the principal's position for both principal and dependents
        if ($isPrincipal) {
            return $entity->position ?? '';
        } else {
            return $principal->position ?? '';
        }
    }
}
