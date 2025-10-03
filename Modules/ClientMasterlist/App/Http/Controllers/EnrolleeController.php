<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\HealthInsurance;
use App\Http\Traits\UppercaseInput;
use App\Http\Traits\PasswordDeleteValidation;

class EnrolleeController extends Controller
{
    use UppercaseInput, PasswordDeleteValidation;

    // List all enrollees for an enrollment
    public function index(Request $request)
    {
        $enrollmentId = $request->query('enrollment_id');
        $perPage = $request->query('per_page', 20);
        $enrollmentStatus = $request->query('enrollment_status');
        $search = $request->query('search');
        $query = Enrollee::with(['dependents', 'healthInsurance']);
        if ($enrollmentId) {
            $query->where('enrollment_id', $enrollmentId);
        }
        if ($enrollmentStatus) {
            $query->where('enrollment_status', $enrollmentStatus);
            if ($enrollmentStatus === 'FOR-RENEWAL') {
                $query->orWhereHas('healthInsurance', function ($q) {
                    $q->where('is_renewal', true);
                });
            }
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%$search%")
                    ->orWhere('last_name', 'like', "%$search%")
                    ->orWhere('employee_id', 'like', "%$search%");
            });
        }
        $query->whereNull('deleted_at');
        $query->orderBy('id', 'desc');

        $enrollees = $query->paginate($perPage);
        return response()->json($enrollees);
    }

    // Store a new enrollee with dependents
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'birth_date' => 'required|date',
            'gender' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'with_dependents' => 'nullable|boolean',
            'email1' => 'required|email',
            'email2' => 'nullable|email',
            'phone1' => 'nullable|string|max:255',
            'phone2' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'employment_start_date' => 'nullable|date',
            'employment_end_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'enrollment_status' => 'required|string',
            'status' => 'required|string|in:ACTIVE,INACTIVE',
            'enrollment_id' => 'required',
        ]);
        $insuranceData = $request->validate([
            'plan' => 'nullable|string|max:255',
            'premium' => 'nullable|numeric',
            'principal_mbl' => 'nullable|numeric',
            'principal_room_and_board' => 'nullable|string|max:255',
            'dependent_mbl' => 'nullable|numeric',
            'dependent_room_and_board' => 'nullable|string|max:255',
            'is_renewal' => 'nullable|boolean',
            'is_company_paid' => 'nullable|boolean',
            'coverage_start_date' => 'nullable|date',
            'coverage_end_date' => 'nullable|date',
            'certificate_number' => 'nullable|string|max:255',
            'certificate_date_issued' => 'nullable|date',
        ]);
        $data = $this->uppercaseStrings($validated);
        $enrollee = Enrollee::create($data);
        // Save health insurance
        $insuranceData['principal_id'] = $enrollee->id;
        $insuranceData = $this->uppercaseStrings($insuranceData);
        HealthInsurance::create($insuranceData);
        // ...existing code for dependents (if needed)...
        $enrollee->load(['dependents', 'healthInsurance']);
        return response()->json($enrollee, 201);
    }

    public function show($id)
    {
        $enrollee = Enrollee::with(['dependents', 'healthInsurance'])->findOrFail($id);
        return response()->json($enrollee);
    }

    // Update an enrollee and its dependents
    public function update(Request $request, $id)
    {
        $enrollee = Enrollee::findOrFail($id);
        $validated = $request->validate([
            'employee_id' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'birth_date' => 'required|date',
            'gender' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'with_dependents' => 'nullable|boolean',
            'email1' => 'required|email',
            'email2' => 'nullable|email',
            'phone1' => 'nullable|string|max:255',
            'phone2' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'employment_start_date' => 'nullable|date',
            'employment_end_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'enrollment_status' => 'required|string',
            'status' => 'required|string|in:ACTIVE,INACTIVE',
            'enrollment_id' => 'required',
        ]);
        $insuranceData = $request->validate([
            'plan' => 'nullable|string|max:255',
            'premium' => 'nullable|numeric',
            'principal_mbl' => 'nullable|numeric',
            'principal_room_and_board' => 'nullable|string|max:255',
            'dependent_mbl' => 'nullable|numeric',
            'dependent_room_and_board' => 'nullable|string|max:255',
            'is_renewal' => 'nullable|boolean',
            'is_company_paid' => 'nullable|boolean',
            'coverage_start_date' => 'nullable|date',
            'coverage_end_date' => 'nullable|date',
            'certificate_number' => 'nullable|string|max:255',
            'certificate_date_issued' => 'nullable|date',
        ]);
        $data = $this->uppercaseStrings($validated);
        $enrollee->update($data);
        // Save or update health insurance
        $insuranceData['principal_id'] = $enrollee->id;
        $insurance = HealthInsurance::where('principal_id', $enrollee->id)->first();
        $insuranceData = $this->uppercaseStrings($insuranceData);
        if ($insurance) {
            $insurance->update($insuranceData);
        } else {
            HealthInsurance::create($insuranceData);
        }

        // If is_company_paid is present, update all dependents' health insurance to match
        if (array_key_exists('is_company_paid', $insuranceData)) {
            $dependents = $enrollee->dependents;
            foreach ($dependents as $dependent) {
                $depInsurance = HealthInsurance::where('dependent_id', $dependent->id)->first();
                if ($depInsurance) {
                    $depInsurance->is_company_paid = $insuranceData['is_company_paid'];
                    $depInsurance->save();
                }
            }
        }

        // ...existing code for dependents (if needed)...
        $enrollee->load(['dependents', 'healthInsurance']);
        return response()->json($enrollee);
    }

    // Export enrollees as CSV
    public function exportEnrollees(Request $request)
    {
        $enrollmentId = $request->query('enrollment_id');
        $enrollmentStatus = $request->query('enrollment_status');
        $columns = $request->query('columns', []);
        $withDependents = $request->query('with_dependents', false);

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
            $query->where('enrollment_status', $enrollmentStatus);
            if ($enrollmentStatus === 'FOR-RENEWAL') {
                $query->orWhereHas('healthInsurance', function ($q) {
                    $q->where('is_renewal', true);
                });
            }
        }

        $query->whereNull('deleted_at');
        $enrollees = $query->get();

        // Map column keys to user-friendly labels (no commas)
        $columnLabels = [
            'remarks' => 'Remarks',
            'reason_for_skipping' => 'Reason for Skipping',
            'attachment' => 'Attachment for Skip Hierarchy',
            'required_document' => 'Required Document',
            'relation' => 'Relation',
            'enrollment_status' => 'Enrollment Status',
            'employee_id' => 'Employee ID',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'middle_name' => 'Middle Name',
            'birth_date' => 'Birth Dates',
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
                                    return 'Do not enroll, skipped hierarchy';
                                } else {
                                    return 'Do not enroll, overage';
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
            $value = str_replace(["\r", "\n", "\t"], ' ', $value);
            return '"' . str_replace('"', '""', $value) . '"';
        };
        $csv = '';
        $csv .= implode(',', array_map($sanitize, $header)) . "\r\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map($sanitize, $row)) . "\r\n";
        }

        $filename = 'EXPORT_ENROLLEES_' . ($enrollmentStatus || 'ALL') . '_' . date('Ymd_His') . '.csv';
        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    // Soft delete an enrollee and its dependents, with password check
    public function destroy(Request $request, $id)
    {
        $user = $this->validateDeletePassword($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }
        $enrollee = Enrollee::findOrFail($id);
        $enrollee->dependents()->delete();
        // Set deleted_by before soft delete
        $enrollee->deleted_by = auth()->id() ?? ($user->id ?? null);
        $enrollee->save();
        $enrollee->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
