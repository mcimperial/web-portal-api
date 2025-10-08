<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Models\Enrollee;
use App\Http\Traits\UppercaseInput;

class ExportEnrolleesController extends Controller
{
    use UppercaseInput;

    public function exportEnrollees(Request $request)
    {
        $enrollmentId = $request->query('enrollment_id');
        $enrollmentStatus = $request->query('enrollment_status');
        $withDependents = $request->query('with_dependents', false);
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $columns = $request->query('columns', []);

        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }

        // Remove empty and duplicate columns, ensure indexed array
        $columns = array_values(array_unique(array_filter($columns, function ($v) {
            return trim($v) !== '';
        })));

        // Add relation column if withDependents is true and not present
        if ($withDependents && !in_array('relation', $columns)) {
            $columns[] = 'relation';
        }

        // Always add enrollment_status
        if (!in_array('enrollment_status', $columns)) {
            $columns[] = 'enrollment_status';
        }

        // Check if any dependent has SKIPPED or OVERAGE status, and if any has a required document
        $hasSkippedOrOverage = false;
        $hasRequiredDocument = false;
        $queryCheck = Enrollee::with('dependents')->where('status', 'ACTIVE')->whereNull('deleted_at');

        if ($enrollmentId) {
            $queryCheck->where('enrollment_id', $enrollmentId);
        }

        if ($enrollmentStatus) {
            $queryCheck->where('enrollment_status', $enrollmentStatus);
        }

        // Apply date range filter on updated_at
        if ($dateFrom) {
            $queryCheck->where('updated_at', '>=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo) {
            $queryCheck->where('updated_at', '<=', $dateTo . ' 23:59:59');
        }

        $queryCheck->whereNull('deleted_at');
        $enrolleesCheck = $queryCheck->get();

        foreach ($enrolleesCheck as $enrollee) {
            if ($enrollee->dependents && count($enrollee->dependents) > 0) {
                foreach ($enrollee->dependents as $dependent) {
                    if (in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                        $hasSkippedOrOverage = true;
                    }
                    if (method_exists($dependent, 'attachmentForRequirement') && $dependent->attachmentForRequirement && $dependent->attachmentForRequirement->file_path) {
                        $hasRequiredDocument = true;
                    }
                    if ($hasSkippedOrOverage && $hasRequiredDocument) {
                        break 2;
                    }
                }
            }
        }

        // Add Required Document column if needed
        if ($hasRequiredDocument) {
            $columns = array_filter($columns, function ($col) {
                return $col !== 'required_document';
            });
            $columns = array_merge($columns, ['required_document']);
        }

        // Add remarks, reason_for_skipping, attachment columns only if needed
        if ($hasSkippedOrOverage) {
            $columns = array_filter($columns, function ($col) {
                return !in_array($col, ['remarks', 'reason_for_skipping', 'attachment_for_skip_hierarchy']);
            });
            $columns = array_merge(['remarks', 'reason_for_skipping', 'attachment_for_skip_hierarchy'], $columns);
        }

        $query = Enrollee::with(['healthInsurance', 'dependents'])->where('status', 'ACTIVE')->whereNull('deleted_at');

        if ($enrollmentId) {
            $query->where('enrollment_id', $enrollmentId);
        }

        if ($enrollmentStatus) {
            if ($enrollmentStatus === 'FOR-RENEWAL') {
                $query->where(function ($q) use ($enrollmentStatus) {
                    $q->where('enrollment_status', $enrollmentStatus)
                        ->orWhereHas('healthInsurance', function ($subQ) {
                            $subQ->where('is_renewal', true);
                        });
                });
            } else {
                $query->where('enrollment_status', $enrollmentStatus);
            }
        }

        // Apply date range filter on updated_at
        if ($dateFrom) {
            $query->where('updated_at', '>=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo) {
            $query->where('updated_at', '<=', $dateTo . ' 23:59:59');
        }

        $query->whereNull('deleted_at');
        $enrollees = $query->get();

        // Map column keys to user-friendly labels (no commas)
        $columnLabels = [
            'remarks' => 'Remarks',
            'reason_for_skipping' => 'Reason for Skipping',
            'attachment' => 'Attachment for Skip Hierarchy',
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

        $header = array_map(function ($col) use ($columnLabels) {
            return $columnLabels[$col] ?? $col;
        }, $columns);

        $insuranceFields = [
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

        $rows = [];
        $colCount = count($columns);

        foreach ($enrollees as $enrollee) {
            // Principal row
            $row = array_map(function ($col) use ($enrollee, $insuranceFields, $withDependents) {
                if ($col === 'required_document') {
                    return '';
                }
                if ($col === 'relation' && $withDependents) {
                    return 'PRINCIPAL';
                }
                if ($col === 'enrollment_status') {
                    return $enrollee->enrollment_status ?? '';
                }
                if ($col === 'remarks' || $col === 'reason_for_skipping' || $col === 'attachment_for_skip_hierarchy') {
                    return '';
                }
                if ($col === 'full_name') {
                    return trim($enrollee->first_name . ' ' . ($enrollee->middle_name ?? '') . ' ' . $enrollee->last_name);
                }
                if (in_array($col, $insuranceFields)) {
                    return $enrollee->healthInsurance ? ($enrollee->healthInsurance->$col ?? '') : '';
                } else {
                    return $enrollee->$col ?? '';
                }
            }, $columns);
            if (count($row) < $colCount) {
                $row = array_pad($row, $colCount, '');
            }
            if (count($row) > $colCount) {
                $row = array_slice($row, 0, $colCount);
            }
            $rows[] = $row;
            // Dependents rows
            if ($withDependents && $enrollee->dependents && count($enrollee->dependents) > 0) {
                foreach ($enrollee->dependents as $dependent) {
                    $depRow = array_map(function ($col) use ($dependent, $withDependents, $insuranceFields) {
                        if ($col === 'required_document') {
                            if (method_exists($dependent, 'attachmentForRequirement') && $dependent->attachmentForRequirement && $dependent->attachmentForRequirement->file_path) {
                                return $dependent->attachmentForRequirement->file_path;
                            }
                            return '';
                        }
                        if ($col === 'remarks') {
                            if (in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                                if ($dependent->enrollment_status === 'SKIPPED') {
                                    return 'DO NOT ENROLL, SKIPPED HIERARCHY';
                                } else {
                                    return 'DO NOT ENROLL, OVERAGE';
                                }
                            }
                            return '';
                        }
                        if ($col === 'reason_for_skipping') {
                            if (in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                                return $dependent->healthInsurance->reason_for_skipping ?? '';
                            }
                            return '';
                        }
                        if ($col === 'attachment_for_skip_hierarchy') {
                            if (in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                                return $dependent->attachmentForSkipHierarchy->file_path ?? '';
                            }
                            return '';
                        }
                        if ($col === 'relation' && $withDependents) {
                            return $dependent->relation ?? '';
                        }
                        if ($col === 'enrollment_status') {
                            return $dependent->enrollment_status ?? '';
                        }
                        if (in_array($col, $insuranceFields)) {
                            return $dependent->healthInsurance ? ($dependent->healthInsurance->$col ?? '') : '';
                        }
                        return $dependent->$col ?? '';
                    }, $columns);
                    if (count($depRow) < $colCount) {
                        $depRow = array_pad($depRow, $colCount, '');
                    }
                    if (count($depRow) > $colCount) {
                        $depRow = array_slice($depRow, 0, $colCount);
                    }
                    $rows[] = $depRow;
                }
            }
        }

        // Build CSV string
        $sanitize = function ($value) {
            // Ensure the value is properly encoded as UTF-8
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            $value = str_replace(["\r", "\n", "\t"], ' ', $value);
            return '"' . str_replace('"', '""', $value) . '"';
        };

        // Start with UTF-8 BOM to ensure proper encoding in Excel
        $csv = "\xEF\xBB\xBF";
        $csv .= implode(',', array_map($sanitize, $header)) . "\r\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map($sanitize, $row)) . "\r\n";
        }

        $filename = 'EXPORT_ENROLLEES_' . ($enrollmentStatus || 'ALL') . '_' . date('Ymd_His') . '.csv';
        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function exportEnrolleesForAttachment(Request $request)
    {
        $enrollmentId = $request->query('enrollment_id');
        $enrollmentStatus = $request->query('enrollment_status');
        $withDependents = $request->query('with_dependents', false);
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $columns = $request->query('columns', []);

        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }

        // Remove empty and duplicate columns, ensure indexed array
        $columns = array_values(array_unique(array_filter($columns, function ($v) {
            return trim($v) !== '';
        })));

        // Add relation column if withDependents is true and not present
        if ($withDependents && !in_array('relation', $columns)) {
            $columns[] = 'relation';
        }

        // Always add enrollment_status
        if (!in_array('enrollment_status', $columns)) {
            $columns[] = 'enrollment_status';
        }

        // Check if any dependent has SKIPPED or OVERAGE status, and if any has a required document
        $hasSkippedOrOverage = false;
        $hasRequiredDocument = false;
        $queryCheck = Enrollee::with('dependents')->where('status', 'ACTIVE')->whereNull('deleted_at');

        if ($enrollmentId) {
            $queryCheck->where('enrollment_id', $enrollmentId);
        }

        if ($enrollmentStatus) {
            $queryCheck->where('enrollment_status', $enrollmentStatus);
        }

        // Apply date range filter on updated_at
        if ($dateFrom) {
            $queryCheck->where('updated_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $queryCheck->where('updated_at', '<=', $dateTo);
        }

        $enrolleesCheck = $queryCheck->get();

        foreach ($enrolleesCheck as $enrollee) {
            if ($enrollee->dependents && count($enrollee->dependents) > 0) {
                foreach ($enrollee->dependents as $dependent) {
                    if (in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                        $hasSkippedOrOverage = true;
                    }
                    if (method_exists($dependent, 'attachmentForRequirement') && $dependent->attachmentForRequirement && $dependent->attachmentForRequirement->file_path) {
                        $hasRequiredDocument = true;
                    }
                    if ($hasSkippedOrOverage && $hasRequiredDocument) {
                        break 2;
                    }
                }
            }
        }

        // Add Required Document column if needed
        if ($hasRequiredDocument) {
            $columns = array_filter($columns, function ($col) {
                return $col !== 'required_document';
            });
            $columns = array_merge($columns, ['required_document']);
        }

        // Add remarks, reason_for_skipping, attachment columns only if needed
        if ($hasSkippedOrOverage) {
            $columns = array_filter($columns, function ($col) {
                return !in_array($col, ['remarks', 'reason_for_skipping', 'attachment_for_skip_hierarchy']);
            });
            $columns = array_merge(['remarks', 'reason_for_skipping', 'attachment_for_skip_hierarchy'], $columns);
        }

        $query = Enrollee::with(['healthInsurance', 'dependents'])->where('status', 'ACTIVE')->whereNull('deleted_at');

        if ($enrollmentId) {
            $query->where('enrollment_id', $enrollmentId);
        }

        if ($enrollmentStatus) {
            if ($enrollmentStatus === 'FOR-RENEWAL') {
                $query->where(function ($q) use ($enrollmentStatus) {
                    $q->where('enrollment_status', $enrollmentStatus)
                        ->orWhereHas('healthInsurance', function ($subQ) {
                            $subQ->where('is_renewal', true);
                        });
                });
            } else {
                $query->where('enrollment_status', $enrollmentStatus);
            }
        }

        // Apply date range filter on updated_at
        if ($dateFrom) {
            $query->where('updated_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('updated_at', '<=', $dateTo);
        }

        $query->whereNull('deleted_at');
        $enrollees = $query->get();

        // Map column keys to user-friendly labels (no commas)
        $columnLabels = [
            'remarks' => 'Remarks',
            'reason_for_skipping' => 'Reason for Skipping',
            'attachment' => 'Attachment for Skip Hierarchy',
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

        $header = array_map(function ($col) use ($columnLabels) {
            return $columnLabels[$col] ?? $col;
        }, $columns);

        $insuranceFields = [
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

        $rows = [];
        $colCount = count($columns);

        foreach ($enrollees as $enrollee) {
            // Principal row
            $row = array_map(function ($col) use ($enrollee, $insuranceFields, $withDependents) {
                if ($col === 'required_document') {
                    return '';
                }
                if ($col === 'relation' && $withDependents) {
                    return 'PRINCIPAL';
                }
                if ($col === 'enrollment_status') {
                    return $enrollee->enrollment_status ?? '';
                }
                if ($col === 'remarks' || $col === 'reason_for_skipping' || $col === 'attachment_for_skip_hierarchy') {
                    return '';
                }
                if ($col === 'full_name') {
                    return trim($enrollee->first_name . ' ' . ($enrollee->middle_name ?? '') . ' ' . $enrollee->last_name);
                }
                if (in_array($col, $insuranceFields)) {
                    return $enrollee->healthInsurance ? ($enrollee->healthInsurance->$col ?? '') : '';
                } else {
                    return $enrollee->$col ?? '';
                }
            }, $columns);
            if (count($row) < $colCount) {
                $row = array_pad($row, $colCount, '');
            }
            if (count($row) > $colCount) {
                $row = array_slice($row, 0, $colCount);
            }
            $rows[] = $row;

            // Dependents rows
            if ($withDependents && $enrollee->dependents && count($enrollee->dependents) > 0) {
                foreach ($enrollee->dependents as $dependent) {
                    $depRow = array_map(function ($col) use ($dependent, $withDependents, $insuranceFields) {
                        if ($col === 'required_document') {
                            if (method_exists($dependent, 'attachmentForRequirement') && $dependent->attachmentForRequirement && $dependent->attachmentForRequirement->file_path) {
                                return $dependent->attachmentForRequirement->file_path;
                            }
                            return '';
                        }
                        if ($col === 'remarks') {
                            if (in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                                if ($dependent->enrollment_status === 'SKIPPED') {
                                    return 'DO NOT ENROLL, SKIPPED HIERARCHY';
                                } else {
                                    return 'DO NOT ENROLL, OVERAGE';
                                }
                            }
                            return '';
                        }
                        if ($col === 'reason_for_skipping') {
                            if (in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                                return $dependent->healthInsurance->reason_for_skipping ?? '';
                            }
                            return '';
                        }
                        if ($col === 'attachment_for_skip_hierarchy') {
                            if (in_array($dependent->enrollment_status, ['SKIPPED', 'OVERAGE'])) {
                                return $dependent->attachmentForSkipHierarchy->file_path ?? '';
                            }
                            return '';
                        }
                        if ($col === 'relation' && $withDependents) {
                            return $dependent->relation ?? '';
                        }
                        if ($col === 'enrollment_status') {
                            return $dependent->enrollment_status ?? '';
                        }
                        if (in_array($col, $insuranceFields)) {
                            return $dependent->healthInsurance ? ($dependent->healthInsurance->$col ?? '') : '';
                        }
                        return $dependent->$col ?? '';
                    }, $columns);
                    if (count($depRow) < $colCount) {
                        $depRow = array_pad($depRow, $colCount, '');
                    }
                    if (count($depRow) > $colCount) {
                        $depRow = array_slice($depRow, 0, $colCount);
                    }
                    $rows[] = $depRow;
                }
            }
        }

        // Build CSV string
        $sanitize = function ($value) {
            // Ensure the value is properly encoded as UTF-8
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            $value = str_replace(["\r", "\n", "\t"], ' ', $value);
            return '"' . str_replace('"', '""', $value) . '"';
        };

        // Start with UTF-8 BOM to ensure proper encoding in Excel
        $csv = "\xEF\xBB\xBF";
        $csv .= implode(',', array_map($sanitize, $header)) . "\r\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map($sanitize, $row)) . "\r\n";
        }

        // Update status of SUBMITTED enrollees to FOR-APPROVAL
        if ($enrollmentStatus === 'SUBMITTED') {
            foreach ($enrollees as $enrollee) {
                if ($enrollee->enrollment_status === 'SUBMITTED') {
                    $enrollee->enrollment_status = 'FOR-APPROVAL';
                    $enrollee->save();
                }
            }
        }

        $filename = 'EXPORT_ENROLLEES_' . ($enrollmentStatus || 'ALL') . '_' . date('Ymd_His') . '.csv';
        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Test method to verify status update functionality
     */
    public function testStatusUpdate(Request $request)
    {
        try {
            // Find SUBMITTED enrollees
            $submittedEnrollees = Enrollee::where('enrollment_status', 'SUBMITTED')
                ->get();

            $result = [
                'message' => 'Status update test completed',
                'submitted_enrollees_found' => $submittedEnrollees->count(),
                'enrollees_before_update' => $submittedEnrollees->map(function ($enrollee) {
                    return [
                        'id' => $enrollee->id,
                        'employee_id' => $enrollee->employee_id,
                        'name' => $enrollee->first_name . ' ' . $enrollee->last_name,
                        'status' => $enrollee->enrollment_status
                    ];
                })
            ];

            // Test the export function with SUBMITTED status to trigger update
            if ($submittedEnrollees->count() > 0) {
                $testRequest = new Request([
                    'enrollment_status' => 'SUBMITTED',
                    'columns' => ['employee_id', 'first_name', 'last_name', 'enrollment_status']
                ]);

                // Call the export function to trigger status update
                $this->exportEnrolleesForAttachment($testRequest);

                // Check updated enrollees
                $updatedEnrollees = Enrollee::whereIn('id', $submittedEnrollees->pluck('id'))
                    ->get();

                $result['enrollees_after_update'] = $updatedEnrollees->map(function ($enrollee) {
                    return [
                        'id' => $enrollee->id,
                        'employee_id' => $enrollee->employee_id,
                        'name' => $enrollee->first_name . ' ' . $enrollee->last_name,
                        'status' => $enrollee->enrollment_status
                    ];
                });
            }

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Test failed',
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }
}
