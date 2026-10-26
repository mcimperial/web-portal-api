<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ClientMasterlist\App\Models\BillingRecord;

class BillingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!Schema::hasTable('cm_billing_records')) {
            return response()->json([]);
        }

        $validated = $request->validate([
            'enrollment_id' => ['nullable', 'integer', 'exists:cm_enrollment,id'],
            'billing_type' => ['nullable', 'string', 'in:credit_memo,modal_billing,adjustment_billing'],
            'billing_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'billing_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = BillingRecord::query()->with(['enrollment', 'importedBy']);

        if (!empty($validated['enrollment_id'])) {
            $query->where('enrollment_id', $validated['enrollment_id']);
        }

        if (!empty($validated['billing_type'])) {
            $query->where('billing_type', $validated['billing_type']);
        }

        if (!empty($validated['billing_month'])) {
            $query->where('billing_month', $validated['billing_month']);
        }

        if (!empty($validated['billing_year'])) {
            $query->where('billing_year', $validated['billing_year']);
        }

        $records = $query
            ->orderByDesc('id')
            ->limit($validated['per_page'] ?? 200)
            ->get();

        return response()->json($records);
    }

    public function store(Request $request): JsonResponse
    {
        if (!Schema::hasTable('cm_billing_records')) {
            return response()->json([
                'message' => 'Billing table is not installed yet. Run the billing migration first.',
            ], 503);
        }

        $validated = $request->validate([
            'enrollment_id' => ['required', 'integer', 'exists:cm_enrollment,id'],
            'billing_type' => ['nullable', 'string', 'in:credit_memo,modal_billing,adjustment_billing'],
            'billing_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'billing_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'employee_id_column_key' => ['nullable', 'string', 'max:255'],
            'birthdate_column_key' => ['nullable', 'string', 'max:255'],
            'source_file_name' => ['nullable', 'string', 'max:255'],
            'sheet_name' => ['nullable', 'string', 'max:255'],
            'columns' => ['required', 'array', 'min:1'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.source_row_number' => ['required', 'integer', 'min:1'],
            'rows.*.principal_id' => ['nullable', 'integer'],
            'rows.*.dependent_id' => ['nullable', 'integer'],
            'rows.*.row_data' => ['required', 'array'],
        ]);

        $billingType = $validated['billing_type'] ?? 'credit_memo';
        $isStatusDrivenBilling = in_array($billingType, ['modal_billing', 'adjustment_billing'], true);

        if ($isStatusDrivenBilling) {
            $request->validate([
                'billing_month' => ['required', 'integer', 'min:1', 'max:12'],
                'billing_year' => ['required', 'integer', 'min:2000', 'max:2100'],
                'employee_id_column_key' => ['required', 'string', 'max:255'],
                'birthdate_column_key' => ['required', 'string', 'max:255'],
            ]);
        }

        $importedRows = [];
        $modalReconReport1 = [];
        $modalReconReport2 = [];
        $modalReconReport3 = [];

        DB::transaction(function () use (&$importedRows, &$modalReconReport1, &$modalReconReport2, &$modalReconReport3, $validated, $request, $billingType, $isStatusDrivenBilling) {
            if ($isStatusDrivenBilling) {
                BillingRecord::query()
                    ->where('enrollment_id', $validated['enrollment_id'])
                    ->where('billing_type', $billingType)
                    ->where('billing_month', $validated['billing_month'])
                    ->where('billing_year', $validated['billing_year'])
                    ->delete();
            }

            foreach ($validated['rows'] as $row) {
                $principalId = $row['principal_id'] ?? null;
                $dependentId = $row['dependent_id'] ?? null;
                $rowData = $row['row_data'];

                if ($isStatusDrivenBilling) {
                    $modalResolution = $this->resolveModalBillingStatus(
                        $validated['enrollment_id'],
                        $rowData,
                        $validated['employee_id_column_key'],
                        $validated['birthdate_column_key'],
                        (int) $validated['billing_month'],
                        (int) $validated['billing_year']
                    );

                    $principalId = $modalResolution['principal_id'];
                    $dependentId = $modalResolution['dependent_id'];
                    $rowData = $modalResolution['row_data'];

                    $reportRowData = $this->buildModalReconReportRowData($rowData);
                    $statusBucket = strtoupper(trim((string) ($reportRowData['SYSTEM_STATUS'] ?? '')));

                    if (in_array($statusBucket, ['ACTIVE', 'INACTIVE', 'NOT_FOUND', 'PENDING_EMPLOYEE', 'PENDING_HMO'], true)) {
                        $modalReconReport1[] = $reportRowData;
                    }

                    if ($billingType === 'modal_billing' && in_array($statusBucket, ['PENDING_EMPLOYEE', 'PENDING_HMO'], true)) {
                        $modalReconReport3[] = $reportRowData;
                    }
                }

                $payload = [
                    'enrollment_id' => $validated['enrollment_id'],
                    'billing_type' => $billingType,
                    'billing_month' => $validated['billing_month'] ?? null,
                    'billing_year' => $validated['billing_year'] ?? null,
                    'principal_id' => $principalId,
                    'dependent_id' => $dependentId,
                    'source_file_name' => $validated['source_file_name'] ?? null,
                    'sheet_name' => $validated['sheet_name'] ?? null,
                    'source_row_number' => $row['source_row_number'],
                    'column_names' => $validated['columns'],
                    'row_data' => $rowData,
                    'imported_by_user_id' => $request->user()?->id,
                ];

                $lookup = [
                    'enrollment_id' => $validated['enrollment_id'],
                    'billing_type' => $billingType,
                    'billing_month' => $validated['billing_month'] ?? null,
                    'billing_year' => $validated['billing_year'] ?? null,
                ];

                $hasMatchKey = !is_null($principalId) || !is_null($dependentId);

                if ($isStatusDrivenBilling) {
                    $lookup['sheet_name'] = $validated['sheet_name'] ?? null;
                    $lookup['source_row_number'] = $row['source_row_number'];
                } elseif ($hasMatchKey) {
                    $lookup['principal_id'] = $principalId;
                    $lookup['dependent_id'] = $dependentId;
                } else {
                    $lookup['principal_id'] = null;
                    $lookup['dependent_id'] = null;
                    $lookup['sheet_name'] = $validated['sheet_name'] ?? null;
                    $lookup['source_row_number'] = $row['source_row_number'];
                }

                $importedRows[] = BillingRecord::updateOrCreate($lookup, $payload);
            }
        });

        if ($billingType === 'modal_billing') {
            $modalReconReport2 = $this->buildModalSystemNotFoundReport(
                (int) $validated['enrollment_id'],
                $validated['rows'],
                (string) $validated['employee_id_column_key'],
                (string) $validated['birthdate_column_key']
            );
        }

        return response()->json([
            'message' => 'Billing rows imported successfully.',
            'billing_type' => $billingType,
            'count' => count($importedRows),
            'records' => $importedRows,
            'reports' => [
                'recon_report_1' => $modalReconReport1,
                'recon_report_2' => $modalReconReport2,
                'recon_report_3' => $modalReconReport3,
            ],
        ], 201);
    }

    private function resolveModalBillingStatus(
        int $enrollmentId,
        array $rowData,
        string $employeeIdColumnKey,
        string $birthdateColumnKey,
        int $billingMonth,
        int $billingYear
    ): array {
        $employeeId = trim((string) ($rowData[$employeeIdColumnKey] ?? ''));
        $birthDateRaw = trim((string) ($rowData[$birthdateColumnKey] ?? ''));
        $birthDate = null;

        if ($birthDateRaw !== '') {
            try {
                $birthDate = Carbon::parse($birthDateRaw)->format('Y-m-d');
            } catch (\Throwable $exception) {
                $birthDate = null;
            }
        }

        $rowData['ACCOUNT_NO'] = null;
        $rowData['COVERAGE_START_DATE'] = null;
        $rowData['COVERAGE_END_DATE'] = null;
        $rowData['SYSTEM_STATUS'] = 'NOT_FOUND';

        if ($employeeId === '' || !$birthDate) {
            return [
                'principal_id' => null,
                'dependent_id' => null,
                'row_data' => $rowData,
            ];
        }

        $healthRecord = DB::table('cm_health_insurance as hi')
            ->leftJoin('cm_principal as p', 'p.id', '=', 'hi.principal_id')
            ->leftJoin('cm_dependent as d', 'd.id', '=', 'hi.dependent_id')
            ->leftJoin('cm_principal as dp', 'dp.id', '=', 'd.principal_id')
            ->where(function ($identityQuery) use ($employeeId, $birthDate) {
                $identityQuery->where(function ($principalIdentityQuery) use ($employeeId, $birthDate) {
                    $principalIdentityQuery
                        ->whereNotNull('hi.principal_id')
                        ->where('p.employee_id', $employeeId)
                        ->whereDate('p.birth_date', $birthDate);
                })->orWhere(function ($dependentIdentityQuery) use ($employeeId, $birthDate) {
                    $dependentIdentityQuery
                        ->whereNotNull('hi.dependent_id')
                        ->where('d.employee_id', $employeeId)
                        ->whereDate('d.birth_date', $birthDate);
                });
            })
            ->where(function ($query) use ($enrollmentId) {
                $query->where(function ($principalQuery) use ($enrollmentId) {
                    $principalQuery
                        ->whereNotNull('hi.principal_id')
                        ->where('p.enrollment_id', $enrollmentId)
                        ->whereNull('p.deleted_at');
                })->orWhere(function ($dependentQuery) use ($enrollmentId) {
                    $dependentQuery
                        ->whereNotNull('hi.dependent_id')
                        ->where('dp.enrollment_id', $enrollmentId)
                        ->whereNull('d.deleted_at')
                        ->whereNull('dp.deleted_at');
                });
            })
            ->select([
                'hi.principal_id',
                'hi.dependent_id',
                'hi.coverage_start_date',
                'hi.coverage_end_date',
                'hi.certificate_number',
                DB::raw('COALESCE(d.enrollment_status, p.enrollment_status) as enrollment_status'),
            ])
            ->orderByDesc('hi.id')
            ->first();

        if (!$healthRecord) {
            return [
                'principal_id' => null,
                'dependent_id' => null,
                'row_data' => $rowData,
            ];
        }

        $resolvedCoverageStartDate = $healthRecord->coverage_start_date
            ? Carbon::parse($healthRecord->coverage_start_date)->format('Y-m-d')
            : null;

        $resolvedCoverageEndDate = $healthRecord->coverage_end_date
            ? Carbon::parse($healthRecord->coverage_end_date)->format('Y-m-d')
            : null;

        $rowData['ACCOUNT_NO'] = $healthRecord->certificate_number ?: null;
        $rowData['COVERAGE_START_DATE'] = $resolvedCoverageStartDate;
        $rowData['COVERAGE_END_DATE'] = $resolvedCoverageEndDate;

        $normalizedEnrollmentStatus = strtoupper((string) ($healthRecord->enrollment_status ?? ''));

        $mappedPendingStatus = $this->mapEnrollmentStatusToPendingSystemStatus($normalizedEnrollmentStatus);

        if ($mappedPendingStatus !== null) {
            $rowData['SYSTEM_STATUS'] = $mappedPendingStatus;
        } elseif ($resolvedCoverageEndDate && $this->isCoverageEndDateOnOrAfterBillingMonthYear($billingMonth, $billingYear, $resolvedCoverageEndDate)) {
            $rowData['SYSTEM_STATUS'] = 'ACTIVE';
        } else {
            $rowData['SYSTEM_STATUS'] = 'INACTIVE';
        }

        return [
            'principal_id' => $healthRecord->principal_id,
            'dependent_id' => $healthRecord->dependent_id,
            'row_data' => $rowData,
        ];
    }

    private function isCoverageEndDateOnOrAfterBillingMonthYear(int $billingMonth, int $billingYear, string $coverageEndDate): bool
    {
        $billingReferenceDate = Carbon::create($billingYear, $billingMonth, 1)->startOfMonth();
        $coverageReferenceDate = Carbon::parse($coverageEndDate)->startOfMonth();

        return $billingReferenceDate->lessThanOrEqualTo($coverageReferenceDate);
    }

    private function normalizeStatusToken(string $status): string
    {
        return strtoupper(str_replace(['_', ' '], '-', trim($status)));
    }

    private function mapEnrollmentStatusToPendingSystemStatus(string $status): ?string
    {
        $normalized = $this->normalizeStatusToken($status);

        if (in_array($normalized, ['PENDING', 'SUBMITTED-PERSONAL-INFROMATION', 'SUBMITTED-PERSONAL-INFORMATION'], true)) {
            return 'PENDING_EMPLOYEE';
        }

        if (in_array($normalized, ['SUBMITTED', 'FOR-APPROVAL'], true)) {
            return 'PENDING_HMO';
        }

        return null;
    }

    private function buildModalReconReportRowData(array $rowData): array
    {
        $status = strtoupper(trim((string) ($rowData['SYSTEM_STATUS'] ?? '')));
        $normalizedStatus = $this->normalizeStatusToken($status);

        if ($normalizedStatus === 'APPROVED') {
            $rowData['SYSTEM_STATUS'] = 'NOT_FOUND';
            return $rowData;
        }

        $mappedPendingStatus = $this->mapEnrollmentStatusToPendingSystemStatus($normalizedStatus);
        if ($mappedPendingStatus !== null) {
            $rowData['SYSTEM_STATUS'] = $mappedPendingStatus;
            return $rowData;
        }

        if (in_array($normalizedStatus, ['ACTIVE', 'INACTIVE', 'NOT-FOUND'], true)) {
            $rowData['SYSTEM_STATUS'] = $normalizedStatus === 'NOT-FOUND' ? 'NOT_FOUND' : $normalizedStatus;
        }

        return $rowData;
    }

    private function buildModalSystemNotFoundReport(
        int $enrollmentId,
        array $uploadedRows,
        string $employeeIdColumnKey,
        string $birthdateColumnKey
    ): array {
        $uploadedIdentities = [];

        foreach ($uploadedRows as $row) {
            $rowData = $row['row_data'] ?? [];
            $employeeId = $this->normalizeIdentityValue($rowData[$employeeIdColumnKey] ?? null);
            $birthDate = $this->normalizeDateValue($rowData[$birthdateColumnKey] ?? null);

            if ($employeeId === '' || $birthDate === null) {
                continue;
            }

            $uploadedIdentities[$employeeId . '|' . $birthDate] = true;
        }

        $systemRows = DB::table('cm_health_insurance as hi')
            ->leftJoin('cm_principal as p', 'p.id', '=', 'hi.principal_id')
            ->leftJoin('cm_dependent as d', 'd.id', '=', 'hi.dependent_id')
            ->leftJoin('cm_principal as dp', 'dp.id', '=', 'd.principal_id')
            ->where(function ($query) use ($enrollmentId) {
                $query->where(function ($principalQuery) use ($enrollmentId) {
                    $principalQuery
                        ->whereNotNull('hi.principal_id')
                        ->where('p.enrollment_id', $enrollmentId)
                        ->whereNull('p.deleted_at');
                })->orWhere(function ($dependentQuery) use ($enrollmentId) {
                    $dependentQuery
                        ->whereNotNull('hi.dependent_id')
                        ->where('dp.enrollment_id', $enrollmentId)
                        ->whereNull('d.deleted_at')
                        ->whereNull('dp.deleted_at');
                });
            })
            ->select([
                'hi.id as health_insurance_id',
                'hi.principal_id',
                'hi.dependent_id',
                'hi.coverage_start_date',
                'hi.certificate_number',
                'p.employee_id as principal_employee_id',
                'p.first_name as principal_first_name',
                'p.middle_name as principal_middle_name',
                'p.last_name as principal_last_name',
                'p.suffix as principal_suffix',
                'p.birth_date as principal_birth_date',
                'd.employee_id as dependent_employee_id',
                'd.first_name as dependent_first_name',
                'd.middle_name as dependent_middle_name',
                'd.last_name as dependent_last_name',
                'd.suffix as dependent_suffix',
                'd.birth_date as dependent_birth_date',
                DB::raw('COALESCE(d.enrollment_status, p.enrollment_status) as enrollment_status'),
            ])
            ->orderByDesc('hi.id')
            ->get();

        $seenSystemIdentities = [];
        $reportRows = [];

        foreach ($systemRows as $systemRow) {
            $enrollmentStatus = strtoupper(trim((string) ($systemRow->enrollment_status ?? '')));
            if (!in_array($enrollmentStatus, ['ACTIVE', 'APPROVED'], true)) {
                continue;
            }

            $isDependent = !is_null($systemRow->dependent_id);
            $employeeId = $this->normalizeIdentityValue(
                $isDependent ? $systemRow->dependent_employee_id : $systemRow->principal_employee_id
            );
            $birthDate = $this->normalizeDateValue(
                $isDependent ? $systemRow->dependent_birth_date : $systemRow->principal_birth_date
            );

            if ($employeeId === '' || $birthDate === null) {
                continue;
            }

            $identityKey = $employeeId . '|' . $birthDate;

            if (isset($seenSystemIdentities[$identityKey])) {
                continue;
            }
            $seenSystemIdentities[$identityKey] = true;

            if (isset($uploadedIdentities[$identityKey])) {
                continue;
            }

            $fullName = $this->buildPersonFullName(
                $isDependent ? $systemRow->dependent_first_name : $systemRow->principal_first_name,
                $isDependent ? $systemRow->dependent_middle_name : $systemRow->principal_middle_name,
                $isDependent ? $systemRow->dependent_last_name : $systemRow->principal_last_name,
                $isDependent ? $systemRow->dependent_suffix : $systemRow->principal_suffix
            );

            $reportRows[] = [
                'employee_id' => $employeeId,
                'full_name' => $fullName,
                'relation' => $isDependent ? 'DEPENDENT' : 'PRINCIPAL',
                'birth_date' => $birthDate,
                'EFFECTIVE_DATE' => $this->normalizeDateValue($systemRow->coverage_start_date),
                'ACCOUNT_NO' => $systemRow->certificate_number ? trim((string) $systemRow->certificate_number) : null,
                'SYSTEM_STATUS' => 'NOT_FOUND',
            ];
        }

        return $reportRows;
    }

    private function normalizeIdentityValue(mixed $value): string
    {
        return strtoupper(trim((string) ($value ?? '')));
    }

    private function normalizeDateValue(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->format('Y-m-d');
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function buildPersonFullName(mixed $firstName, mixed $middleName, mixed $lastName, mixed $suffix): string
    {
        $parts = array_filter([
            trim((string) ($firstName ?? '')),
            trim((string) ($middleName ?? '')),
            trim((string) ($lastName ?? '')),
            trim((string) ($suffix ?? '')),
        ], static fn ($value) => $value !== '');

        return implode(' ', $parts);
    }
}