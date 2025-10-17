<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\ClientMasterlist\App\Models\Enrollee;
use App\Http\Traits\UppercaseInput;

class ExportEnrolleesController extends Controller
{
    use UppercaseInput;

    /**
     * Column mappings for CSV headers
     */
    private const COLUMN_LABELS = [
        'remarks' => 'Remarks',
        'reason_for_skipping' => 'Reason for Skipping',
        'attachment' => 'Attachment for Skip Hierarchy',
        'attachment_for_skip_hierarchy' => 'Attachment for Skip Hierarchy',
        'required_document' => 'Required Document',
        'enrollment_status' => 'Enrollment Status',
        'relation' => 'Relation',
        'employee_id' => 'Employee ID',
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'middle_name' => 'Middle Name',
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
     * Build base query with common filters
     */
    private function buildBaseQuery($filters = [])
    {
        $query = Enrollee::with(['healthInsurance', 'dependents'])
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
        } else {
            Log::info('Skipping enrollment status filter - no enrollment_status provided', [
                'filters' => $filters
            ]);
        }

        // Apply date range filters
        if (!empty($filters['date_from'])) {
            $query->where('updated_at', '>=', $filters['date_from'] . ' 00:00:00');
        }

        if (!empty($filters['date_to'])) {
            $query->where('updated_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        return $query;
    }

    /**
     * Apply enrollment status filters based on export type
     */
    private function applyEnrollmentStatusFilter($query, $filters)
    {
        #test
        $enrollmentStatus = $filters['enrollment_status'];
        $exportType = $filters['export_enrollment_type'];

        Log::info('Inside applyEnrollmentStatusFilter', [
            'enrollmentStatus' => $enrollmentStatus,
            'exportType' => $exportType
        ]);

        if ($exportType === 'RENEWAL') {
            Log::info('Applying RENEWAL export filters');
            // For RENEWAL exports, ALWAYS ensure is_renewal is true first

            $query->whereHas('healthInsurance', function ($subQ) {
                $subQ->where('is_renewal', true);
            });

            if ($enrollmentStatus === 'PENDING') {
                Log::info('RENEWAL with PENDING status - filtering for FOR-RENEWAL');
                // enrollment_status is FOR-RENEWAL (since PENDING renewals show as FOR-RENEWAL)
                $query->where('enrollment_status', 'FOR-RENEWAL');
            } else {
                if (isset($enrollmentStatus)) {
                    Log::info('RENEWAL with other status', ['status' => $enrollmentStatus]);
                    // For other statuses in RENEWAL export
                    $query->where('enrollment_status', $enrollmentStatus);
                }
            }
        } elseif ($exportType === 'REGULAR') {
            $query->whereHas('healthInsurance', function ($subQ) {
                $subQ->where('is_renewal', false);
            });
            Log::info('Applying REGULAR export filters');
            // For REGULAR exports, ensure is_renewal is false
            if (isset($enrollmentStatus)) {
                Log::info('RENEWAL with other status', ['status' => $enrollmentStatus]);
                // For other statuses in RENEWAL export
                $query->where('enrollment_status', $enrollmentStatus);
            }
        } else {
            Log::info('Applying ALL export filters (no specific type)');
            // For ALL exports (no specific type filter)
            if ($enrollmentStatus === 'PENDING') {
                Log::info('ALL export with PENDING status - filtering for FOR-RENEWAL OR PENDING');
                $query->where(function ($q) {
                    $q->where('enrollment_status', 'FOR-RENEWAL')
                        ->orWhere('enrollment_status', 'PENDING');
                });
            } else {
                if (isset($enrollmentStatus)) {
                    Log::info('RENEWAL with other status', ['status' => $enrollmentStatus]);
                    // For other statuses in RENEWAL export
                    $query->where('enrollment_status', $enrollmentStatus);
                }
            }
        }
    }

    /**
     * Process and validate columns, adding dynamic columns as needed
     */
    private function processColumns($columns, $withDependents, $enrollees)
    {
        // Normalize columns input
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }

        // Remove empty and duplicate columns
        $columns = array_values(array_unique(array_filter($columns, function ($v) {
            return trim($v) !== '';
        })));

        // Add relation column if withDependents is true
        if ($withDependents && !in_array('relation', $columns)) {
            $columns[] = 'relation';
        }

        // Always add enrollment_status
        if (!in_array('enrollment_status', $columns)) {
            $columns[] = 'enrollment_status';
        }

        // Check for special cases in dependents and add columns accordingly
        $hasSkippedOrOverage = false;
        $hasRequiredDocument = false;

        foreach ($enrollees as $enrollee) {
            if ($enrollee->dependents && count($enrollee->dependents) > 0) {
                foreach ($enrollee->dependents as $dependent) {
                    if (in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                        $hasSkippedOrOverage = true;
                    }
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
            $skipColumns = ['remarks', 'reason_for_skipping', 'attachment_for_skip_hierarchy'];
            foreach ($skipColumns as $skipCol) {
                if (!in_array($skipCol, $columns)) {
                    array_unshift($columns, $skipCol);
                }
            }
        }

        return $columns;
    }

    /**
     * Generate CSV headers from column names
     */
    private function generateHeaders($columns)
    {
        return array_map(function ($col) {
            return self::COLUMN_LABELS[$col] ?? $col;
        }, $columns);
    }

    /**
     * Generate row data for principal enrollee
     */
    private function generatePrincipalRow($enrollee, $columns, $withDependents)
    {
        return array_map(function ($col) use ($enrollee, $withDependents) {
            return $this->getColumnValue($col, $enrollee, $withDependents, true, null);
        }, $columns);
    }

    /**
     * Generate row data for dependent
     */
    private function generateDependentRow($dependent, $columns, $withDependents, $principal = null)
    {
        return array_map(function ($col) use ($dependent, $withDependents, $principal) {
            return $this->getColumnValue($col, $dependent, $withDependents, false, $principal);
        }, $columns);
    }

    /**
     * Get value for a specific column and entity (enrollee or dependent)
     */
    private function getColumnValue($column, $entity, $withDependents, $isPrincipal, $principal = null)
    {
        switch ($column) {
            case 'required_document':
                if (
                    !$isPrincipal && method_exists($entity, 'attachmentForRequirement') &&
                    $entity->attachmentForRequirement && $entity->attachmentForRequirement->file_path
                ) {
                    return $entity->attachmentForRequirement->file_path;
                }
                return '';

            case 'relation':
                return $withDependents ? ($isPrincipal ? 'PRINCIPAL' : ($entity->relation ?? '')) : 'PRINCIPAL';

            case 'department':
                return !$isPrincipal && $principal ? ($principal->department ?? '') : ($entity->department ?? '');

            case 'position':
                return !$isPrincipal && $principal ? ($principal->position ?? '') : ($entity->position ?? '');

            case 'enrollment_status':
                return $entity->enrollment_status ?? '';

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

            case 'full_name':
                return trim($entity->first_name . ' ' . ($entity->middle_name ?? '') . ' ' . $entity->last_name);

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
    private function createCsvResponse($csv, $enrollmentStatus)
    {
        $filename = 'EXPORT_ENROLLEES_' . ($enrollmentStatus ?: 'ALL') . '_' . date('Ymd_His') . '.csv';

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Main export method - handles both regular and attachment exports
     */
    public function exportEnrollees(Request $request)
    {
        // Extract request parameters
        $filters = [
            'enrollment_id' => $request->query('enrollment_id'),
            'export_enrollment_type' => $request->query('export_enrollment_type'),
            'enrollment_status' => $request->query('enrollment_status'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        $withDependents = $request->query('with_dependents', false);
        $isForAttachment = $request->query('for_attachment', false);

        $columns = $request->query('columns', []);

        // Build query and get enrollees
        $query = $this->buildBaseQuery($filters);

        // Log the SQL query for debugging
        Log::info('Export Enrollees SQL Query', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
            'filters' => $filters
        ]);

        $enrollees = $query->get();

        // Process columns based on enrollee data
        $columns = $this->processColumns($columns, $withDependents, $enrollees);

        // Generate CSV data
        $headers = $this->generateHeaders($columns);
        $rows = $this->generateAllRows($enrollees, $columns, $withDependents);

        // Generate CSV content
        $csv = $this->generateCsv($headers, $rows);

        // Handle status updates for attachment exports
        if ($isForAttachment && $filters['enrollment_status'] === 'SUBMITTED') {
            $this->updateSubmittedEnrollees($enrollees);
        }

        return $this->createCsvResponse($csv, $filters['enrollment_status']);
    }

    /**
     * Generate all rows (principals and dependents)
     */
    private function generateAllRows($enrollees, $columns, $withDependents)
    {
        $rows = [];
        $colCount = count($columns);

        foreach ($enrollees as $enrollee) {
            // Add principal row
            $row = $this->generatePrincipalRow($enrollee, $columns, $withDependents);
            $rows[] = $this->normalizeRowLength($row, $colCount);

            // Add dependent rows if needed
            if ($withDependents && $enrollee->dependents && count($enrollee->dependents) > 0) {
                foreach ($enrollee->dependents as $dependent) {
                    $depRow = $this->generateDependentRow($dependent, $columns, $withDependents, $enrollee);
                    $rows[] = $this->normalizeRowLength($depRow, $colCount);
                }
            }
        }

        return $rows;
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
     * Legacy method for attachment exports - now redirects to main method
     */
    public function exportEnrolleesForAttachment(Request $request)
    {
        // Add for_attachment flag to indicate this is an attachment export
        $request->merge(['for_attachment' => true]);

        return $this->exportEnrollees($request);
    }
}
