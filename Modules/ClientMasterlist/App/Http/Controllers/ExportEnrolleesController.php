<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\ClientMasterlist\App\Models\Enrollee;
use App\Http\Traits\UppercaseInput;
use App\Models\Company;

class ExportEnrolleesController extends Controller
{
    use UppercaseInput;

    /**
     * Column mappings for CSV headers
     */
    private const COLUMN_LABELS = [
        'remarks' => 'Remarks',
        'reason_for_skipping' => 'Reason for Skipping',
        'attachment_for_skip_hierarchy' => 'Attachment for Skip Hierarchy',
        'effective_date' => 'Effective Date',
        //'attachment' => 'Attachment for Skip Hierarchy',
        'required_document' => 'Required Document',
        'enrollment_status' => 'Enrollment Status',
        'relation' => 'Relation',
        'employee_id' => 'Employee ID',
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'middle_name' => 'Middle Name',
        'suffix' => 'Suffix',
        'birth_date' => 'Birth Date',
        'gender' => 'Gender',
        'marital_status' => 'Marital Status',
        'email1' => 'Email 1',
        'email2' => 'Email 2',
        'phone1' => 'Phone 1',
        'phone2' => 'Phone 2',
        'address' => 'Address',
        'department' => 'Department',
        'position' => 'Position',
        'employment_start_date' => 'Employment Start Date',
        'employment_end_date' => 'Employment End Date',
        'notes' => 'Notes',
        'status' => 'Status',
        // health insurance fields
        'plan' => 'Plan',
        'premium' => 'Premium',
        'principal_mbl' => 'Principal MBL',
        'principal_room_and_board' => 'Principal Room and Board',
        'dependent_mbl' => 'Dependent MBL',
        'dependent_room_and_board' => 'Dependent Room and Board',
        'is_renewal' => 'Is Renewal',
        'is_company_paid' => 'Is Company Paid',
        'coverage_start_date' => 'Coverage Start Date',
        'coverage_end_date' => 'Coverage End Date',
        'certificate_number' => 'Certificate Number',
        'certificate_date_issued' => 'Certificate Date Issued',
    ];

    /**
     * Fields that come from the health insurance relationship
     */
    private const INSURANCE_FIELDS = [
        'plan',
        'premium',
        'principal_mbl',
        'principal_room_and_board',
        'dependent_mbl',
        'dependent_room_and_board',
        'is_renewal',
        'is_company_paid',
        'coverage_start_date',
        'coverage_end_date',
        'certificate_number',
        'certificate_date_issued'
    ];

    /**
     * Maxicare specific custom columns
     */
    private const MAXICARE_COLUMN_LABELS = [
        // Maxicare specific add-on columns
        'maxicare_account_code' => 'Account Code',
        'maxicare_employee_id' => 'Employee No',
        'maxicare_first_name' => 'First Name',
        'maxicare_last_name' => 'Last Name',
        'maxicare_middle_name' => 'Middle Name',
        'maxicare_extension' => 'Extension',
        'maxicare_mononymous' => 'Mononymous',
        'maxicare_gender' => 'Gender',
        'maxicare_member_type' => 'Member Type',
        'maxicare_relation' => 'Relationship',
        'maxicare_address_line_1' => 'Address Line 1',
        'maxicare_address_line_2' => 'Address Line 2',
        'maxicare_city' => 'City',
        'maxicare_province' => 'Province',
        'maxicare_postal_code' => 'Postal Code',
        'maxicare_civil_status' => 'Civil Status',
        'maxicare_birth_date' => 'Birth Date',
        'maxicare_mobile_no' => 'Mobile No',
        'maxicare_email' => 'Email',
        'maxicare_effective_date' => 'Effective Date',
        'maxicare_date_hired' => 'Date Hired',
        'maxicare_date_regularization' => 'Date Regularization',
        'maxicare_philhealth' => 'PhilHealth',
        'maxicare_plan_code' => 'Plan Code',
        'maxicare_card_issuance' => 'Card Issuance',
        'maxicare_remarks' => 'Remarks',
        'maxicare_birth_certificate' => 'Birth Certificate',
        'maxicare_employee_cenomar' => 'Employee CENOMAR',
        'maxicare_partner_cenomar' => 'Partner CENOMAR',
        'maxicare_employee_barangay_certification' => 'Employee Barangay Certification',
        'maxicare_partner_barangay_certification' => 'Partner Barangay Certification',
    ];

    /**
     * specific custom headers for automatic export
     */
    private const AUTO_HEADER = [
        'enrollment_status',
        'relation',
        'employee_id',
        'first_name',
        'last_name',
        'middle_name',
        'suffix',
        'birth_date',
        'marital_status',
        'gender',
        'email1',
        'phone1',
        'department',
        'position',
        'effective_date',
    ];

    private const AUTO_MAXICARE_CUSTOM_HEADER = [
        'maxicare_account_code',
        'maxicare_employee_id',
        'maxicare_first_name',
        'maxicare_last_name',
        'maxicare_middle_name',
        'maxicare_extension',
        'maxicare_mononymous',
        'maxicare_gender',
        'maxicare_member_type',
        'maxicare_relation',
        'maxicare_address_line_1',
        'maxicare_address_line_2',
        'maxicare_city',
        'maxicare_province',
        'maxicare_postal_code',
        'maxicare_civil_status',
        'maxicare_birth_date',
        'maxicare_mobile_no',
        'maxicare_email',
        'maxicare_effective_date',
        'maxicare_date_hired',
        'maxicare_date_regularization',
        'maxicare_philhealth',
        'maxicare_plan_code',
        'maxicare_card_issuance',
        'maxicare_remarks',
        'maxicare_birth_certificate',
        'maxicare_employee_cenomar',
        'maxicare_partner_cenomar',
        'maxicare_employee_barangay_certification',
        'maxicare_partner_barangay_certification',
    ];

    /**
     * Main export method - handles both regular and attachment exports
     */
    public function exportEnrollees(Request $request)
    {
        // Extract request parameters
        $filters = [
            'maxicare_customized_column' => $request->query('maxicare_customized_column'),
            'enrollment_id' => $request->query('enrollment_id'),
            'export_enrollment_type' => $request->query('export_enrollment_type'),
            'enrollment_status' => $request->query('enrollment_status'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        $withDependents = $request->query('with_dependents', false);
        $isForAttachment = $request->query('for_attachment', false);

        // Build query and get enrollees
        $query = $this->buildBaseQuery($filters);

        // Log the SQL query for debugging
        Log::info('Export Enrollees SQL Query', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
            'filters' => $filters
        ]);

        $enrollees = $query->get();

        // Debug: Log the is_renewal values for REGULAR exports
        if ($filters['export_enrollment_type'] === 'REGULAR') {
            Log::info('DEBUG: Checking is_renewal values for REGULAR export', [
                'total_enrollees' => $enrollees->count()
            ]);

            foreach ($enrollees as $index => $enrollee) {
                $isRenewal = $enrollee->healthInsurance ? $enrollee->healthInsurance->is_renewal : 'no_health_insurance';
                Log::info("DEBUG: Enrollee #{$index}", [
                    'enrollee_id' => $enrollee->id,
                    'employee_id' => $enrollee->employee_id,
                    'is_renewal' => $isRenewal,
                    'has_health_insurance' => $enrollee->healthInsurance ? 'yes' : 'no'
                ]);

                // Only log first 5 to avoid overwhelming logs
                if ($index >= 4) {
                    Log::info('DEBUG: Truncating debug logs after 5 records...');
                    break;
                }
            }
        }

        if ($filters['maxicare_customized_column']) {
            Log::info('Maxicare customized column is true. Adding Maxicare specific columns.');
            // Merge Maxicare specific columns
            $columns = self::AUTO_MAXICARE_CUSTOM_HEADER;
        } else {
            if ($isForAttachment) {
                $columns = self::AUTO_HEADER;
            } else {
                $columns = $request->query('columns', []);
            }
        }

        // Process columns based on enrollee data
        $columns = $this->processColumns($columns, $enrollees, $filters['maxicare_customized_column']);

        // Generate CSV data
        $headers = $this->generateHeaders($columns, $filters['maxicare_customized_column']);
        $rows = $this->generateAllRows($enrollees, $columns, $withDependents, $filters['maxicare_customized_column']);

        // Generate CSV content
        $csv = $this->generateCsv($headers, $rows);

        // Handle status updates for attachment exports
        if ($isForAttachment && $filters['enrollment_status'] === 'SUBMITTED') {
            $this->updateSubmittedEnrollees($enrollees);
        }

        return $this->createCsvResponse($csv, $filters['enrollment_status'], $enrollees);
    }

    /**
     * Legacy method for attachment exports - now redirects to main method
     */
    public function exportEnrolleesForAttachment(Request $request)
    {
        // Add for_attachment flag to indicate this is an attachment export
        $request->merge(['for_attachment' => true]);

        return $this->exportEnrollees($request);
    }

    /**
     * Build base query with common filters
     */
    private function buildBaseQuery($filters = [])
    {
        // Build the relationships array based on enrollment status filter
        $relationships = ['healthInsurance', 'enrollment.insuranceProvider'];

        if (isset($filters['enrollment_status']) && $filters['enrollment_status'] === 'APPROVED') {
            // When filtering for APPROVED status, only load APPROVED dependents
            Log::info('Applying APPROVED enrollment status filter - will only include APPROVED dependents');
            $relationships['dependents'] = function ($query) {
                $query->where('enrollment_status', 'APPROVED')
                    ->with(['healthInsurance', 'attachmentForSkipHierarchy', 'attachmentForRequirement', 'requiredDocuments', 'attachments']);
            };
        } else {
            // Load all active dependents (default behavior) with all their attachments
            $relationships['dependents'] = function ($query) {
                $query->with(['healthInsurance', 'attachmentForSkipHierarchy', 'attachmentForRequirement', 'requiredDocuments', 'attachments']);
            };
        }

        $query = Enrollee::with($relationships)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at');

        // Apply enrollment ID filter
        if (!empty($filters['enrollment_id'])) {
            $query->where('enrollment_id', $filters['enrollment_id']);
        }

        // Apply enrollment status filter
        if (isset($filters['enrollment_status']) || isset($filters['export_enrollment_type'])) {
            Log::info('Applying enrollment status filter', [
                'enrollment_status' => $filters['enrollment_status'],
                'export_enrollment_type' => $filters['export_enrollment_type'] ?? null
            ]);
            $this->applyEnrollmentStatusFilter($query, $filters);
        }

        // Apply date range filters
        if (!empty($filters['date_from'])) {
            if ($filters['enrollment_status'] === 'APPROVED') {
                $query->whereHas('healthInsurance', function ($subQ) use ($filters) {
                    // Filter records where coverage starts on or after the date_from
                    $subQ->where(function ($dateQ) use ($filters) {
                        $dateQ->where('coverage_start_date', '<', $filters['date_from'])
                            ->orWhereNull('coverage_start_date');
                    });
                });
            } else {
                $query->where('updated_at', '>=', $filters['date_from'] . ' 00:00:00');
            }
        }

        if (!empty($filters['date_to'])) {
            if ($filters['enrollment_status'] === 'APPROVED') {
                $query->whereHas('healthInsurance', function ($subQ) use ($filters) {
                    // Filter records where coverage ends on or before the date_to
                    $subQ->where(function ($dateQ) use ($filters) {
                        $dateQ->where('coverage_end_date', '<', $filters['date_to'])
                            ->orWhereNull('coverage_end_date');
                    });
                });
            } else {
                $query->where('updated_at', '<=', $filters['date_to'] . ' 23:59:59');
            }
        }

        return $query;
    }

    /**
     * Apply enrollment status filters based on export type
     */
    private function applyEnrollmentStatusFilter($query, $filters)
    {
        $enrollmentStatus = $filters['enrollment_status'];
        $exportType = $filters['export_enrollment_type'];

        Log::info('Applying enrollment status filter', [
            'enrollment_status' => $enrollmentStatus,
            'export_enrollment_type' => $exportType
        ]);

        if ($exportType === 'RENEWAL') {
            Log::info('Applying RENEWAL export filters');
            // For RENEWAL exports, ALWAYS ensure is_renewal is true first

            $query->whereHas('healthInsurance', function ($subQ) {
                $subQ->where('is_renewal', true);
            });

            if ($enrollmentStatus === 'PENDING') {
                $query->where('enrollment_status', 'FOR-RENEWAL');
            } else {
                if (isset($enrollmentStatus)) {
                    $query->where('enrollment_status', $enrollmentStatus);
                }
            }
        } elseif ($exportType === 'REGULAR') {
            Log::info('Applying REGULAR export filters - filtering for is_renewal = false');
            // For REGULAR exports, ensure is_renewal is false
            $query->whereHas('healthInsurance', function ($subQ) {
                $subQ->where('is_renewal', false);
            });

            if (isset($enrollmentStatus)) {
                Log::info('REGULAR with enrollment status filter', ['status' => $enrollmentStatus]);
                $query->where('enrollment_status', $enrollmentStatus);
            }
        } else {
            Log::info('Applying ALL export filters (no type restriction)');
            // For ALL exports (no specific type filter)
            if ($enrollmentStatus === 'PENDING') {
                $query->where(function ($q) {
                    $q->where('enrollment_status', 'FOR-RENEWAL')
                        ->orWhere('enrollment_status', 'PENDING');
                });
            } else {
                if (isset($enrollmentStatus)) {
                    Log::info('ALL export with enrollment status filter', ['status' => $enrollmentStatus]);
                    $query->where('enrollment_status', $enrollmentStatus);
                }
            }
        }

        // Log the final SQL query after applying filters
        Log::info('Final query after applying enrollment status filter', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings()
        ]);
    }

    /**
     * Check if this enrollment is for Maxicare insurance provider
     */
    private function isMaxicareEnrollment($enrollees)
    {
        foreach ($enrollees as $enrollee) {
            if (
                $enrollee->enrollment &&
                $enrollee->enrollment->insuranceProvider &&
                strtolower($enrollee->enrollment->insuranceProvider->title) === 'maxicare'
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate CSV headers from column names
     */
    private function generateHeaders($columns, $maxicareCustomizedColumn = false)
    {
        if ($maxicareCustomizedColumn) {
            $headers = array_map(function ($col) {
                return self::MAXICARE_COLUMN_LABELS[$col] ?? $col;
            }, $columns);
        } else {
            $headers = array_map(function ($col) {
                return self::COLUMN_LABELS[$col] ?? $col;
            }, $columns);
        }

        Log::info('Generated headers', ['columns' => $columns, 'headers' => $headers]);
        return $headers;
    }

    /**
     * Process and validate columns, adding dynamic columns as needed
     */
    private function processColumns($columns, $enrollees, $maxicareCustomizedColumn = false)
    {
        // Normalize columns input
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        } elseif (is_array($columns)) {
            // Flatten any nested arrays and ensure all values are strings
            $flatColumns = [];
            array_walk_recursive($columns, function ($value) use (&$flatColumns) {
                if (is_string($value) || is_numeric($value)) {
                    $flatColumns[] = (string)$value;
                }
            });
            $columns = $flatColumns;
        } else {
            // If it's neither string nor array, default to empty array
            $columns = [];
        }

        // Remove empty and duplicate columns - now we're sure all values are strings
        $columns = array_values(array_unique(array_filter($columns, function ($v) {
            return is_string($v) && trim($v) !== '';
        })));

        Log::info('Initial columns after normalization', ['columns' => $columns]);


        if (!$maxicareCustomizedColumn) {
            // Always add effective_date
            if (!in_array('effective_date', $columns)) {
                $columns[] = 'effective_date';
            }

            // Always add enrollment_status
            if (!in_array('enrollment_status', $columns)) {
                $columns[] = 'enrollment_status';
            }

            // Add relation column if withDependents is true
            if (!in_array('relation', $columns)) {
                $columns[] = 'relation';
            }
        }

        Log::info('Columns after adding relation and enrollment_status', ['columns' => $columns]);

        // Check for special cases in dependents and add columns accordingly
        $hasSkippedOrOverage = false;
        $hasRequiredDocument = false;

        foreach ($enrollees as $enrollee) {
            if ($enrollee->dependents && count($enrollee->dependents) > 0) {
                foreach ($enrollee->dependents as $dependent) {
                    if (in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                        $hasSkippedOrOverage = true;
                    }

                    // Check for required documents using the new relationship
                    if ($dependent->requiredDocuments && $dependent->requiredDocuments->count() > 0) {
                        $hasRequiredDocument = true;
                    }

                    // Also check legacy single attachment for backward compatibility
                    if (
                        method_exists($dependent, 'attachmentForRequirement') &&
                        $dependent->attachmentForRequirement &&
                        $dependent->attachmentForRequirement->file_path
                    ) {
                        $hasRequiredDocument = true;
                    }

                    if ($hasSkippedOrOverage && $hasRequiredDocument) {
                        break 2;
                    }
                }
            }
        }

        // Add required document column if needed
        if ($hasRequiredDocument && !in_array('required_document', $columns)) {
            $columns[] = 'required_document';
        }

        // Add skip-related columns if needed
        if ($hasSkippedOrOverage) {
            Log::info('Adding skip-related columns because hasSkippedOrOverage is true');
            $skipColumns = ['remarks', 'reason_for_skipping', 'attachment_for_skip_hierarchy'];
            foreach ($skipColumns as $skipCol) {
                if (!in_array($skipCol, $columns)) {
                    array_unshift($columns, $skipCol);
                }
            }
        }

        Log::info('Final columns array', ['columns' => $columns]);

        return $columns;
    }

    /**
     * Get value for a specific column and entity (enrollee or dependent)
     */
    private function getColumnValue($column, $entity, $withDependents, $isPrincipal, $principal = null)
    {
        switch ($column) {
            case 'remarks':
                if (!$isPrincipal && in_array($entity->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                    return $entity->enrollment_status === 'SKIPPED'
                        ? 'DO NOT ENROLL, SKIPPED HIERARCHY'
                        : 'DO NOT ENROLL, OVERAGE';
                }
                return '';

            case 'reason_for_skipping':
                if (!$isPrincipal && in_array($entity->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                    return $entity->healthInsurance->reason_for_skipping ?? '';
                }
                return '';

            case 'attachment_for_skip_hierarchy':
                if (!$isPrincipal && in_array($entity->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                    return $entity->attachmentForSkipHierarchy->file_path ?? '';
                }
                return '';

            case 'effective_date':
                return date('Y-m-d', strtotime('first day of next month'));

            case 'enrollment_status':
                return $entity->enrollment_status ?? '';

            case 'relation':
                return $withDependents ? ($isPrincipal ? 'PRINCIPAL' : ($entity->relation ?? 'PRINCIPAL')) : 'PRINCIPAL';

            case 'department':
                return !$isPrincipal && $principal ? ($principal->department ?? '') : ($entity->department ?? '');

            case 'position':
                return !$isPrincipal && $principal ? ($principal->position ?? '') : ($entity->position ?? '');

            case 'is_renewal':
                return !$isPrincipal && $principal ? ($principal->healthInsurance->is_renewal ? 'YES' : 'NO') : ($entity->healthInsurance->is_renewal ? 'YES' : 'NO');

            case 'is_company_paid':
                return !$isPrincipal && $principal ? ($principal->healthInsurance->is_company_paid ? 'YES' : 'NO') : ($entity->healthInsurance->is_company_paid ? 'YES' : 'NO');

            case 'required_document':
                if (!$isPrincipal) {
                    // First check if there are multiple required documents using the new relationship
                    if ($entity->requiredDocuments && $entity->requiredDocuments->count() > 0) {
                        $filePaths = $entity->requiredDocuments->pluck('file_path')->toArray();
                        return implode('; ', $filePaths);
                    }

                    // Fallback to legacy single attachment for backward compatibility
                    if (
                        method_exists($entity, 'attachmentForRequirement') &&
                        $entity->attachmentForRequirement && $entity->attachmentForRequirement->file_path
                    ) {
                        return $entity->attachmentForRequirement->file_path;
                    }
                }
                return '';

            default:
                if (in_array($column, self::INSURANCE_FIELDS)) {
                    return $entity->healthInsurance ? ($entity->healthInsurance->$column ?? '') : '';
                }
                return $entity->$column ?? '';
        }
    }

    /**
     * Get value for a specific column and entity (enrollee or dependent)
     */
    private function getColumnValueForMaxicareCustomFields($column, $entity, $isPrincipal, $principal = null)
    {
        switch ($column) {
            case 'remarks':
                if (!$isPrincipal && in_array($entity->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                    return $entity->enrollment_status === 'SKIPPED'
                        ? 'DO NOT ENROLL, SKIPPED HIERARCHY'
                        : 'DO NOT ENROLL, OVERAGE';
                }
                return '';

            case 'reason_for_skipping':
                if (!$isPrincipal && in_array($entity->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                    return $entity->healthInsurance->reason_for_skipping ?? '';
                }
                return '';

            case 'attachment_for_skip_hierarchy':
                if (!$isPrincipal && in_array($entity->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                    return $entity->attachmentForSkipHierarchy->file_path ?? '';
                }
                return '';

            case 'maxicare_account_code':
                // For dependents, use principal's enrollment account code
                $enrollment = $isPrincipal ? $entity->enrollment : ($principal ? $principal->enrollment : null);
                return $enrollment && $enrollment->account_code
                    ? ($enrollment->account_code ?? '')
                    : '';

            case 'maxicare_employee_id':
                return $isPrincipal ? ($entity->employee_id ?? '') : ($principal ? ($principal->employee_id ?? '') : '');

            case 'maxicare_first_name':
                return $entity->first_name ?? '';

            case 'maxicare_last_name':
                return $entity->last_name ?? '';

            case 'maxicare_middle_name':
                return $entity->middle_name ?? '';

            case 'maxicare_extension':
                return $entity->suffix ?? '';

            case 'maxicare_member_type':
                return $isPrincipal ? 'P' : 'D';

            case 'maxicare_gender':
                return $entity->gender === 'MALE' ? 'M' : 'F';

            case 'maxicare_member_type':
                return $isPrincipal ? 'P' : 'D';

            case 'maxicare_relation':
                switch (strtoupper($entity->relation ?? 'P')) {
                    case 'SPOUSE':
                        return 'S';
                    case 'CHILD':
                        return 'C';
                    case 'PARENT':
                        return 'PR';
                    case 'SIBLING':
                        return 'SL';
                    case 'DOMESTIC PARTNER':
                        return 'O';
                    default:
                        return 'P'; // Other
                }

            case 'maxicare_address_line_1':
                // Parse comma-separated address: address_line_1, address_line_2, city, province, postal_code
                $addressParts = $this->parseAddress($entity->address ?? '');
                return $addressParts['address_line_1'];

            case 'maxicare_address_line_2':
                $addressParts = $this->parseAddress($entity->address ?? '');
                return $addressParts['address_line_2'];

            case 'maxicare_city':
                $addressParts = $this->parseAddress($entity->address ?? '');
                return $addressParts['city'];

            case 'maxicare_province':
                $addressParts = $this->parseAddress($entity->address ?? '');
                return $addressParts['province'];

            case 'maxicare_postal_code':
                $addressParts = $this->parseAddress($entity->address ?? '');
                return $addressParts['postal_code'];


            case 'maxicare_civil_status':
                // Use the marital_status from the entity, or default value
                $maritalStatus = $entity->marital_status ?? '';

                switch (strtoupper($maritalStatus)) {
                    case 'SINGLE':
                        return 'S';
                    case 'SINGLE PARENT':
                        return 'SP';
                    case 'MARRIED':
                        return 'M';
                    default:
                        return 'S';
                }

            case 'maxicare_birth_date':
                return $entity->birth_date;

            case 'maxicare_effective_date':
                return date('Y-m-d', strtotime('first day of next month'));

            case 'maxicare_date_hired':
                return $entity->employment_start_date;

            case 'maxicare_date_regularization':
                return $entity->employment_start_date;

            case 'maxicare_philhealth':
                return 'R';

            case 'maxicare_plan_code':
                // Check if entity has own plan
                if (isset($entity->healthInsurance->plan) && !empty($entity->healthInsurance->plan)) {
                    return $this->parsePlanCode($entity->healthInsurance->plan, $isPrincipal);
                }

                // If dependent has no plan, copy from principal
                if (!$isPrincipal && $principal && isset($principal->healthInsurance->plan) && !empty($principal->healthInsurance->plan)) {
                    return $this->parsePlanCode($principal->healthInsurance->plan, false); // Always false for dependent
                }
                // Fallback to default codes
                return $isPrincipal ? 'M0010165856000010P' : 'M0010165856000020D';
            case 'maxicare_card_issuance':
                return 'Y';

            case 'required_document':
                if (!$isPrincipal) {
                    // First check if there are multiple required documents using the new relationship
                    if ($entity->requiredDocuments && $entity->requiredDocuments->count() > 0) {
                        $filePaths = $entity->requiredDocuments->pluck('file_path')->toArray();
                        return implode('; ', $filePaths);
                    }

                    // Fallback to legacy single attachment for backward compatibility
                    if (
                        method_exists($entity, 'attachmentForRequirement') &&
                        $entity->attachmentForRequirement && $entity->attachmentForRequirement->file_path
                    ) {
                        return $entity->attachmentForRequirement->file_path;
                    }
                }
                return '';

            default:
                if (in_array($column, self::INSURANCE_FIELDS)) {
                    return $entity->healthInsurance ? ($entity->healthInsurance->$column ?? '') : '';
                }
                return $entity->$column ?? '';
        }
    }

    /**
     * Generate CSV content from data
     */
    private function generateCsv($headers, $rows)
    {
        $sanitize = function ($value) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            $value = str_replace(["\r", "\n", "\t"], ' ', $value);
            return '"' . str_replace('"', '""', $value) . '"';
        };

        // Start with UTF-8 BOM
        $csv = "\xEF\xBB\xBF";
        $csv .= implode(',', array_map($sanitize, $headers)) . "\r\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map($sanitize, $row)) . "\r\n";
        }

        return $csv;
    }

    /**
     * Create CSV response with proper headers
     */
    private function createCsvResponse($csv, $enrollmentStatus, $enrollees = null)
    {
        // Get company and provider information from the first enrollee
        $company = 'UNKNOWN';
        $provider = 'UNKNOWN';

        if ($enrollees && count($enrollees) > 0) {
            $firstEnrollee = $enrollees->first();

            // Get company code through enrollment relationship
            if ($firstEnrollee->enrollment && $firstEnrollee->enrollment->company_id) {
                $companyRecord = Company::find($firstEnrollee->enrollment->company_id);
                if ($companyRecord && $companyRecord->company_code) {
                    $company = $companyRecord->company_code;
                }
            }

            // Get insurance provider title through enrollment relationship  
            if ($firstEnrollee->enrollment && $firstEnrollee->enrollment->insuranceProvider) {
                $provider = $firstEnrollee->enrollment->insuranceProvider->title ?? 'UNKNOWN';
            }
        }

        $filename = 'EXPORT_C-' . $company . '_P-' . $provider . '_S-' . ($enrollmentStatus ?: 'ALL') . '_DT-' . date('Ymd_His') . '.csv';

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Generate all rows (principals and dependents)
     */
    private function generateAllRows($enrollees, $columns, $withDependents, $maxicareCustomizedColumn = false)
    {
        $rows = [];
        $colCount = count($columns);

        foreach ($enrollees as $enrollee) {
            // Add principal row
            $row = $this->generatePrincipalRow($enrollee, $columns, $withDependents, $maxicareCustomizedColumn);
            $rows[] = $this->normalizeRowLength($row, $colCount);

            // Add dependent rows if needed
            if ($withDependents && $enrollee->dependents && count($enrollee->dependents) > 0) {
                foreach ($enrollee->dependents as $dependent) {
                    $depRow = $this->generateDependentRow($dependent, $columns, $withDependents, $enrollee, $maxicareCustomizedColumn);
                    $rows[] = $this->normalizeRowLength($depRow, $colCount);
                }
            }
        }

        return $rows;
    }

    /**
     * Generate row data for principal enrollee
     */
    private function generatePrincipalRow($enrollee, $columns, $withDependents, $maxicareCustomizedColumn = false)
    {
        if ($maxicareCustomizedColumn) {
            return array_map(function ($col) use ($enrollee) {
                return $this->getColumnValueForMaxicareCustomFields($col, $enrollee, true, null);
            }, $columns);
        } else {
            return array_map(function ($col) use ($enrollee, $withDependents) {
                return $this->getColumnValue($col, $enrollee, $withDependents, true, null);
            }, $columns);
        }
    }

    /**
     * Generate row data for dependent
     */
    private function generateDependentRow($dependent, $columns, $withDependents, $principal = null, $maxicareCustomizedColumn = false)
    {
        if ($maxicareCustomizedColumn) {
            return array_map(function ($col) use ($dependent, $principal) {
                return $this->getColumnValueForMaxicareCustomFields($col, $dependent, false, $principal);
            }, $columns);
        } else {
            return array_map(function ($col) use ($dependent, $withDependents, $principal) {
                return $this->getColumnValue($col, $dependent, $withDependents, false, $principal);
            }, $columns);
        }
    }

    /**
     * Ensure row has correct length
     */
    private function normalizeRowLength($row, $expectedLength)
    {
        if (count($row) < $expectedLength) {
            return array_pad($row, $expectedLength, '');
        } elseif (count($row) > $expectedLength) {
            return array_slice($row, 0, $expectedLength);
        }
        return $row;
    }

    /**
     * Update SUBMITTED enrollees to FOR-APPROVAL status
     */
    private function updateSubmittedEnrollees($enrollees)
    {
        foreach ($enrollees as $enrollee) {
            if ($enrollee->enrollment_status === 'SUBMITTED') {
                $enrollee->enrollment_status = 'FOR-APPROVAL';
                $enrollee->save();
            }
        }
    }

    /**
     * Parse comma-separated address into categorized components
     * Expected format: address_line_1, address_line_2, city, province, postal_code
     *
     * @param string $address
     * @return array
     */
    private function parseAddress($address)
    {
        // Initialize default values
        $addressParts = [
            'address_line_1' => '',
            'address_line_2' => '',
            'city' => '',
            'province' => '',
            'postal_code' => ''
        ];

        if (empty($address)) {
            return $addressParts;
        }

        // Split by comma and trim whitespace
        $parts = array_map('trim', explode(',', $address));

        // Map parts to their respective fields (handle cases where there might be fewer than 5 parts)
        if (isset($parts[0])) $addressParts['address_line_1'] = $parts[0];
        if (isset($parts[1])) $addressParts['address_line_2'] = $parts[1];
        if (isset($parts[2])) $addressParts['city'] = $parts[2];
        if (isset($parts[3])) $addressParts['province'] = $parts[3];
        if (isset($parts[4])) $addressParts['postal_code'] = $parts[4];

        return $addressParts;
    }

    /**
     * Parse plan code based on comma-separated format
     *
     * @param string $plan
     * @param bool $isPrincipal
     * @return string
     */
    private function parsePlanCode($plan, $isPrincipal)
    {
        $plan = trim($plan);

        if (strpos($plan, ',') === false) {
            // Single value: apply P/D logic for dependents
            return (!$isPrincipal && substr($plan, -1) === 'P')
                ? substr($plan, 0, -1) . 'D'
                : $plan;
        }

        $planParts = array_map('trim', explode(',', $plan));
        $partCount = count($planParts);

        if ($partCount == 2) {
            // Format: plan_code_principal,plan_code_dependent
            return $isPrincipal ? $planParts[0] : $planParts[1];
        } elseif ($partCount == 3) {
            // Format: plan_name,plan_code_principal,plan_code_dependent
            return $isPrincipal ? $planParts[1] : $planParts[2];
        } else {
            // Fallback: use first part and apply P/D logic
            $planCode = $planParts[0];
            return (!$isPrincipal && substr($planCode, -1) === 'P')
                ? substr($planCode, 0, -1) . 'D'
                : $planCode;
        }
    }
}
