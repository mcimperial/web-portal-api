<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\ClientMasterlist\App\Models\BillingRecord;
use Modules\ClientMasterlist\App\Models\Dependent;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Enrollment;

class BillingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!Schema::hasTable('cm_billing_records')) {
            return response()->json([]);
        }

        $validated = $request->validate([
            'enrollment_id' => ['nullable', 'integer', 'exists:cm_enrollment,id'],
            'billing_type' => ['nullable', 'string', 'in:credit_memo,modal_billing,adjustment_billing,looker_file_validation,active_list_validation'],
            'billing_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'billing_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'include_reports' => ['nullable', 'boolean'],
        ]);

        $selectedEnrollmentId = isset($validated['enrollment_id'])
            ? (int) $validated['enrollment_id']
            : null;
        $withLowercaseOutput = $this->shouldLowercaseOutputForEnrollment($selectedEnrollmentId);

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

        $query->orderByDesc('id');

        if (!empty($validated['per_page'])) {
            $query->limit((int) $validated['per_page']);
        }

        $records = $query->get();

        $records = $records->map(function (BillingRecord $record) {
            $record->row_data = $this->enrichRowDataWithPrincipalPendingDates(
                (array) ($record->row_data ?? []),
                $record->principal_id,
                $record->dependent_id
            );

            return $record;
        });

        if ($withLowercaseOutput) {
            $records = $records->map(fn(BillingRecord $record) => $this->lowercaseBillingRecordOutput($record));
        }

        $includeReports = (bool) ($validated['include_reports'] ?? false);

        if (!$includeReports) {
            return response()->json($records);
        }

        $billingType = $validated['billing_type'] ?? null;
        $reports = [
            'recon_report_1' => [],
            'recon_report_2' => [],
            'recon_report_3' => [],
        ];

        if (
            !is_null($billingType) &&
            in_array($billingType, ['modal_billing', 'adjustment_billing', 'looker_file_validation', 'active_list_validation'], true)
        ) {
            $reports = $this->buildReportsFromStoredRecords(
                $records,
                $billingType,
                (int) ($validated['enrollment_id'] ?? 0)
            );
        }

        if ($withLowercaseOutput) {
            $reports = $this->lowercaseOutputValue($reports);
        }

        $latestRecord = $records->sortByDesc(static fn ($record) => $record->updated_at ?? $record->created_at)->first();
        $latestImport = null;

        if ($latestRecord) {
            $latestImport = [
                'billing_type' => $latestRecord->billing_type,
                'billing_month' => $latestRecord->billing_month,
                'billing_year' => $latestRecord->billing_year,
                'source_file_name' => $latestRecord->source_file_name,
                'sheet_name' => $latestRecord->sheet_name,
                'imported_at' => $latestRecord->updated_at ?? $latestRecord->created_at,
                'imported_by_user_id' => $latestRecord->imported_by_user_id,
                'imported_by_name' => $latestRecord->importedBy?->name,
                'imported_by_email' => $latestRecord->importedBy?->email,
            ];
        }

        return response()->json([
            'records' => $records,
            'reports' => $reports,
            'latest_import' => $withLowercaseOutput ? $this->lowercaseOutputValue($latestImport) : $latestImport,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!Schema::hasTable('cm_billing_records')) {
            return response()->json([
                'message' => 'Billing table is not installed yet. Run the billing migration first.',
            ], 503);
        }

        $this->prepareImportExecutionBudget();

        $validated = $request->validate([
            'enrollment_id' => ['required', 'integer', 'exists:cm_enrollment,id'],
            'billing_type' => ['nullable', 'string', 'in:credit_memo,modal_billing,adjustment_billing,looker_file_validation,active_list_validation'],
            'billing_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'billing_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'employee_id_column_key' => ['nullable', 'string', 'max:255'],
            'status_column_key' => ['nullable', 'string', 'max:255'],
            'oid_column_key' => ['nullable', 'string', 'max:255'],
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

        $withLowercaseOutput = $this->shouldLowercaseOutputForEnrollment((int) $validated['enrollment_id']);

        $billingType = $validated['billing_type'] ?? 'credit_memo';
        $isPrincipalOnlyValidationBilling = in_array($billingType, ['looker_file_validation', 'active_list_validation'], true);
        $isStatusDrivenBilling = in_array($billingType, ['modal_billing', 'adjustment_billing', 'looker_file_validation', 'active_list_validation'], true);
        $resolvedBillingMonth = isset($validated['billing_month']) ? (int) $validated['billing_month'] : null;
        $resolvedBillingYear = isset($validated['billing_year']) ? (int) $validated['billing_year'] : null;

        if (in_array($billingType, ['modal_billing', 'adjustment_billing'], true)) {
            $request->validate([
                'billing_month' => ['required', 'integer', 'min:1', 'max:12'],
                'billing_year' => ['required', 'integer', 'min:2000', 'max:2100'],
                'employee_id_column_key' => ['required', 'string', 'max:255'],
                'birthdate_column_key' => ['required', 'string', 'max:255'],
            ]);

            $resolvedBillingMonth = (int) $request->input('billing_month');
            $resolvedBillingYear = (int) $request->input('billing_year');
        } elseif ($isPrincipalOnlyValidationBilling) {
            $request->validate([
                'employee_id_column_key' => ['required', 'string', 'max:255'],
            ]);

            if ($billingType === 'looker_file_validation') {
                $request->validate([
                    'status_column_key' => ['required', 'string', 'max:255'],
                ]);
            }
        }

        $this->validateImportColumnMappings($validated, $billingType);

        $importedRows = [];
        $modalReconReport1 = [];
        $modalReconReport2 = [];
        $modalReconReport3 = [];

        DB::transaction(function () use (&$importedRows, &$modalReconReport1, &$modalReconReport2, &$modalReconReport3, $validated, $request, $billingType, $isStatusDrivenBilling, $isPrincipalOnlyValidationBilling, $resolvedBillingMonth, $resolvedBillingYear) {
            if ($isStatusDrivenBilling) {
                $deleteQuery = BillingRecord::query()
                    ->where('enrollment_id', $validated['enrollment_id'])
                    ->where('billing_type', $billingType);

                if (in_array($billingType, ['modal_billing', 'adjustment_billing'], true)) {
                    $deleteQuery
                        ->where('billing_month', $resolvedBillingMonth)
                        ->where('billing_year', $resolvedBillingYear)
                        ->delete();
                } elseif ($isPrincipalOnlyValidationBilling) {
                    $deleteQuery->delete();
                }
            }

            foreach ($validated['rows'] as $row) {
                $principalId = $row['principal_id'] ?? null;
                $dependentId = $row['dependent_id'] ?? null;
                $rowData = $row['row_data'];

                if ($isStatusDrivenBilling) {
                    if ($isPrincipalOnlyValidationBilling) {
                        $modalResolution = $this->resolveLookerFileValidationStatus(
                            $validated['enrollment_id'],
                            $rowData,
                            $validated['employee_id_column_key'] ?? null,
                            $validated['status_column_key'] ?? null,
                            $billingType
                        );
                    } else {
                        $modalResolution = $this->resolveModalBillingStatus(
                            $validated['enrollment_id'],
                            $rowData,
                            $validated['employee_id_column_key'],
                            $validated['birthdate_column_key'],
                            (int) $validated['billing_month'],
                            (int) $validated['billing_year']
                        );
                    }

                    $principalId = $modalResolution['principal_id'];
                    $dependentId = $modalResolution['dependent_id'];
                    $rowData = $modalResolution['row_data'];

                    $reportRowData = $this->buildModalReconReportRowData($rowData);
                    $statusBucket = strtoupper(trim((string) ($reportRowData['SYSTEM_STATUS'] ?? '')));

                    if (in_array($statusBucket, ['ACTIVE', 'INACTIVE', 'TERMINATED', 'INACTIVE - DID NOT TAKE ACTION', 'WAIVED', 'NOT_FOUND', 'PENDING_EMPLOYEE', 'PENDING_HMO'], true)) {
                        $modalReconReport1[] = $reportRowData;
                    }

                    if ($billingType === 'modal_billing' && in_array($statusBucket, ['PENDING_EMPLOYEE', 'PENDING_HMO'], true)) {
                        $modalReconReport3[] = $reportRowData;
                    }
                }

                $payload = [
                    'enrollment_id' => $validated['enrollment_id'],
                    'billing_type' => $billingType,
                    'billing_month' => $resolvedBillingMonth,
                    'billing_year' => $resolvedBillingYear,
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
                    'billing_month' => $resolvedBillingMonth,
                    'billing_year' => $resolvedBillingYear,
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
        } elseif (in_array($billingType, ['looker_file_validation', 'active_list_validation'], true)) {
            $modalReconReport2 = $this->buildLookerSystemNotFoundReport(
                (int) $validated['enrollment_id'],
                $validated['rows'],
                (string) $validated['employee_id_column_key']
            );
        }

        $responsePayload = [
            'message' => 'Billing rows imported successfully.',
            'billing_type' => $billingType,
            'count' => count($importedRows),
            'records' => $importedRows,
            'reports' => [
                'recon_report_1' => $modalReconReport1,
                'recon_report_2' => $modalReconReport2,
                'recon_report_3' => $modalReconReport3,
            ],
        ];

        if ($withLowercaseOutput) {
            $responsePayload['records'] = array_map(
                fn($record) => $this->lowercaseBillingRecordOutput($record),
                $responsePayload['records']
            );
            $responsePayload['reports'] = $this->lowercaseOutputValue($responsePayload['reports']);
        }

        return response()->json($responsePayload, 201);
    }

    private function prepareImportExecutionBudget(): void
    {
        DB::disableQueryLog();

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        @ini_set('max_execution_time', '300');
    }

    private function validateImportColumnMappings(array $validated, string $billingType): void
    {
        $availableColumnKeys = [];

        foreach (($validated['columns'] ?? []) as $column) {
            if (is_string($column)) {
                $resolvedKey = trim($column);
            } elseif (is_array($column)) {
                $resolvedKey = trim((string) ($column['key'] ?? $column['name'] ?? $column['value'] ?? ''));
            } else {
                $resolvedKey = '';
            }

            if ($resolvedKey !== '') {
                $availableColumnKeys[$resolvedKey] = true;
            }
        }

        $requiredMappings = [];

        if (in_array($billingType, ['modal_billing', 'adjustment_billing'], true)) {
            $requiredMappings[] = 'employee_id_column_key';
            $requiredMappings[] = 'birthdate_column_key';
        }

        if (in_array($billingType, ['looker_file_validation', 'active_list_validation'], true)) {
            $requiredMappings[] = 'employee_id_column_key';
        }

        if ($billingType === 'looker_file_validation') {
            $requiredMappings[] = 'status_column_key';
        }

        $mappingErrors = [];

        foreach ($requiredMappings as $mappingField) {
            $selectedKey = trim((string) ($validated[$mappingField] ?? ''));

            if ($selectedKey === '' || !isset($availableColumnKeys[$selectedKey])) {
                $mappingErrors[$mappingField] = [
                    sprintf('The selected mapping "%s" does not exist in the uploaded columns.', $selectedKey),
                ];
            }
        }

        if (!empty($mappingErrors)) {
            throw ValidationException::withMessages($mappingErrors);
        }
    }

    public function softDeleteReconReport2Row(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_id' => ['required', 'integer', 'exists:cm_enrollment,id'],
            'billing_type' => ['required', 'string', 'in:modal_billing,adjustment_billing,looker_file_validation,active_list_validation'],
            'relation' => ['nullable', 'string', 'in:PRINCIPAL,DEPENDENT,principal,dependent'],
            'principal_id' => ['nullable', 'integer', 'min:1'],
            'dependent_id' => ['nullable', 'integer', 'min:1'],
            'employee_id' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'rows' => ['nullable', 'array', 'min:1'],
            'rows.*.relation' => ['nullable', 'string', 'in:PRINCIPAL,DEPENDENT,principal,dependent'],
            'rows.*.principal_id' => ['nullable', 'integer', 'min:1'],
            'rows.*.dependent_id' => ['nullable', 'integer', 'min:1'],
            'rows.*.employee_id' => ['nullable', 'string', 'max:255'],
            'rows.*.birth_date' => ['nullable', 'date'],
        ]);

        $enrollmentId = (int) $validated['enrollment_id'];
        $rowsPayload = is_array($validated['rows'] ?? null)
            ? $validated['rows']
            : [[
                'relation' => $validated['relation'] ?? null,
                'principal_id' => $validated['principal_id'] ?? null,
                'dependent_id' => $validated['dependent_id'] ?? null,
                'employee_id' => $validated['employee_id'] ?? null,
                'birth_date' => $validated['birth_date'] ?? null,
            ]];

        $resolvedTargets = [];
        $seenTargetKeys = [];
        $notFoundCount = 0;

        foreach ($rowsPayload as $rowPayload) {
            $relation = strtoupper(trim((string) ($rowPayload['relation'] ?? 'PRINCIPAL')));
            $principalId = isset($rowPayload['principal_id']) ? (int) $rowPayload['principal_id'] : null;
            $dependentId = isset($rowPayload['dependent_id']) ? (int) $rowPayload['dependent_id'] : null;
            $employeeId = $this->normalizeIdentityValue($rowPayload['employee_id'] ?? null);
            $birthDate = $this->normalizeDateValue($rowPayload['birth_date'] ?? null);

            if (is_null($principalId) && is_null($dependentId) && $employeeId === '') {
                $notFoundCount++;
                continue;
            }

            $target = $this->resolveReconReport2SoftDeleteTarget(
                $enrollmentId,
                $relation,
                $principalId,
                $dependentId,
                $employeeId,
                $birthDate
            );

            if (is_null($target)) {
                $notFoundCount++;
                continue;
            }

            $targetKey = $target['type'] === 'dependent'
                ? 'dependent:' . (string) ($target['dependent_id'] ?? 0)
                : 'principal:' . (string) ($target['principal_id'] ?? 0);

            if (isset($seenTargetKeys[$targetKey])) {
                continue;
            }

            $seenTargetKeys[$targetKey] = true;
            $resolvedTargets[] = $target;
        }

        if (count($resolvedTargets) === 0) {
            return response()->json([
                'message' => 'No valid Recon Report 2 row targets found for soft delete.',
            ], 422);
        }

        $actorId = $request->user()?->id;
        $totalDeletedDependentsCount = 0;
        $deletedTargets = [];

        DB::transaction(function () use (&$totalDeletedDependentsCount, &$deletedTargets, $resolvedTargets, $actorId) {
            foreach ($resolvedTargets as $target) {
                if ($target['type'] === 'dependent') {
                    /** @var Dependent $dependent */
                    $dependent = $target['model'];
                    $dependent->deleted_by = $actorId;
                    $dependent->save();
                    $dependent->delete();

                    $deletedTargets[] = [
                        'type' => 'dependent',
                        'principal_id' => $target['principal_id'],
                        'dependent_id' => $target['dependent_id'],
                        'deleted_dependents_count' => 0,
                    ];

                    continue;
                }

                /** @var Enrollee $principal */
                $principal = $target['model'];
                $deletedDependentsCountForPrincipal = 0;

                $activeDependentIds = Dependent::query()
                    ->where('principal_id', $principal->id)
                    ->whereNull('deleted_at')
                    ->pluck('id');

                if ($activeDependentIds->isNotEmpty()) {
                    Dependent::query()
                        ->whereIn('id', $activeDependentIds->all())
                        ->update([
                            'deleted_by' => $actorId,
                        ]);

                    Dependent::query()
                        ->whereIn('id', $activeDependentIds->all())
                        ->delete();

                    $deletedDependentsCountForPrincipal = $activeDependentIds->count();
                    $totalDeletedDependentsCount += $deletedDependentsCountForPrincipal;
                }

                $principal->deleted_by = $actorId;
                $principal->save();
                $principal->delete();

                $deletedTargets[] = [
                    'type' => 'principal',
                    'principal_id' => $target['principal_id'],
                    'dependent_id' => null,
                    'deleted_dependents_count' => $deletedDependentsCountForPrincipal,
                ];
            }
        });

        return response()->json([
            'message' => 'Recon Report 2 soft delete completed.',
            'deleted' => [
                'count' => count($deletedTargets),
                'rows' => $deletedTargets,
                'deleted_dependents_count' => $totalDeletedDependentsCount,
                'not_found_count' => $notFoundCount,
            ],
        ]);
    }

    private function shouldLowercaseOutputForEnrollment(?int $enrollmentId): bool
    {
        if (empty($enrollmentId)) {
            return false;
        }

        $enrollment = Enrollment::query()
            ->select(['id', 'with_lowercase_output'])
            ->find($enrollmentId);

        return (bool) ($enrollment?->with_lowercase_output ?? false);
    }

    private function lowercaseBillingRecordOutput(BillingRecord $record): BillingRecord
    {
        $record->row_data = $this->lowercaseOutputValue((array) ($record->row_data ?? []));
        return $record;
    }

    private function lowercaseOutputValue($value)
    {
        if (is_string($value)) {
            return mb_strtolower($value, 'UTF-8');
        }

        if (is_array($value)) {
            $lowercased = [];
            foreach ($value as $key => $item) {
                $lowercased[$key] = $this->lowercaseOutputValue($item);
            }
            return $lowercased;
        }

        return $value;
    }

    private function resolveModalBillingStatus(
        int $enrollmentId,
        array $rowData,
        string $employeeIdColumnKey,
        string $birthdateColumnKey,
        int $billingMonth,
        int $billingYear
    ): array {
        $employeeId = $this->normalizeIdentityValue($rowData[$employeeIdColumnKey] ?? null);
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
                        ->where(function ($principalEmployeeQuery) use ($employeeId) {
                            $principalEmployeeQuery
                                ->where(DB::raw('UPPER(TRIM(p.employee_id))'), $employeeId)
                                ->orWhere(DB::raw('UPPER(TRIM(p.member_id))'), $employeeId);
                        })
                        ->whereDate('p.birth_date', $birthDate);
                })->orWhere(function ($dependentIdentityQuery) use ($employeeId, $birthDate) {
                    $dependentIdentityQuery
                        ->whereNotNull('hi.dependent_id')
                        ->where(DB::raw('UPPER(TRIM(d.employee_id))'), $employeeId)
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
                DB::raw('COALESCE(dp.created_at, p.created_at) as principal_created_at'),
                DB::raw('COALESCE(dp.submission_date, p.submission_date) as principal_submission_date'),
                'd.enrollment_status as dependent_enrollment_status',
                DB::raw('COALESCE(dp.enrollment_status, p.enrollment_status) as principal_enrollment_status'),
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
        $rowData['CREATED_AT'] = $this->normalizeDateValue($healthRecord->principal_created_at ?? null);
        $rowData['SUBMISSION_DATE'] = $this->normalizeDateValue($healthRecord->principal_submission_date ?? null);

        $effectiveEnrollmentStatus = $this->resolveEffectiveEnrollmentStatusForHealthRecord($healthRecord);
        $rowData['SYSTEM_STATUS'] = $this->resolveSystemStatusByBillingContext(
            'modal_billing',
            $effectiveEnrollmentStatus,
            $healthRecord->certificate_number ?? null,
            $resolvedCoverageEndDate,
            $billingMonth,
            $billingYear
        );

        return [
            'principal_id' => $healthRecord->principal_id,
            'dependent_id' => $healthRecord->dependent_id,
            'row_data' => $rowData,
        ];
    }

    private function resolveLookerFileValidationStatus(
        int $enrollmentId,
        array $rowData,
        ?string $employeeIdColumnKey,
        ?string $statusColumnKey,
        string $billingType
    ): array {
        $employeeId = $this->normalizeIdentityValue(
            $employeeIdColumnKey ? ($rowData[$employeeIdColumnKey] ?? null) : null
        );

        $rowData['ACCOUNT_NO'] = null;
        $rowData['COVERAGE_START_DATE'] = null;
        $rowData['COVERAGE_END_DATE'] = null;
        $rowData['SYSTEM_STATUS'] = 'NOT_FOUND';

        if ($employeeId === '') {
            return [
                'principal_id' => null,
                'dependent_id' => null,
                'row_data' => $rowData,
            ];
        }

        $healthRecord = DB::table('cm_principal as p')
            ->leftJoin('cm_health_insurance as hi', 'hi.principal_id', '=', 'p.id')
            ->where('p.enrollment_id', $enrollmentId)
            ->whereNull('p.deleted_at')
            ->where(function ($query) use ($employeeId) {
                $query
                    ->where(DB::raw('UPPER(TRIM(p.employee_id))'), $employeeId)
                    ->orWhere(DB::raw('UPPER(TRIM(p.member_id))'), $employeeId);
            })
            ->select([
                'p.id as principal_id',
                'p.created_at as principal_created_at',
                'p.submission_date as principal_submission_date',
                'hi.coverage_start_date',
                'hi.coverage_end_date',
                'hi.certificate_number',
                'p.enrollment_status',
            ])
            ->orderByRaw('hi.id IS NULL ASC')
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
        $rowData['CREATED_AT'] = $this->normalizeDateValue($healthRecord->principal_created_at ?? null);
        $rowData['SUBMISSION_DATE'] = $this->normalizeDateValue($healthRecord->principal_submission_date ?? null);

        $rowData['SYSTEM_STATUS'] = $this->resolveSystemStatusByBillingContext(
            $billingType,
            (string) ($healthRecord->enrollment_status ?? ''),
            $healthRecord->certificate_number ?? null,
            $resolvedCoverageEndDate
        );

        if ($billingType === 'looker_file_validation') {
            $systemStatus = strtoupper((string) ($rowData['SYSTEM_STATUS'] ?? ''));
            $lookerStatusRaw = $statusColumnKey ? ($rowData[$statusColumnKey] ?? null) : null;
            $rowData['MATCHED'] = $this->isLookerStatusMatched($systemStatus, $lookerStatusRaw);
        }

        return [
            'principal_id' => $healthRecord->principal_id,
            'dependent_id' => null,
            'row_data' => $rowData,
        ];
    }

    private function resolveLookerExpectedSystemStatus(mixed $statusValue): ?string
    {
        $normalized = $this->normalizeStatusToken((string) ($statusValue ?? ''));

        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['ENROLLED', 'ACTIVE'], true)) {
            return 'ACTIVE';
        }

        if (in_array($normalized, ['NOT-ENROLLED', 'EXPIRED', 'INACTIVE'], true)) {
            return 'INACTIVE';
        }

        if (in_array($normalized, ['OPTED-OUT', 'WAIVED'], true)) {
            return 'WAIVED';
        }

        if ($normalized === 'TERMINATED') {
            return 'TERMINATED';
        }

        if (in_array($normalized, ['INACTIVE-DID-NOT-TAKE-ACTION', 'INACTIVE-DIDNT-TAKE-ACTION'], true)) {
            return 'INACTIVE - DID NOT TAKE ACTION';
        }

        if (in_array($normalized, ['AWAITING-ENROLLMENT', 'PENDING-HMO', 'PENDING-EMPLOYEE', 'AWAITING'], true)) {
            return 'PENDING_ANY';
        }

        return null;
    }

    private function isLookerStatusMatched(string $systemStatus, mixed $lookerStatusRaw): bool
    {
        $normalizedLookerStatus = $this->normalizeStatusToken((string) ($lookerStatusRaw ?? ''));
        $expectedSystemStatus = $this->resolveLookerExpectedSystemStatus($lookerStatusRaw);

        if (in_array($normalizedLookerStatus, ['NOT-ENROLLED', 'NOT-ENROLLED-YES'], true)) {
            return in_array($systemStatus, ['INACTIVE', 'TERMINATED', 'INACTIVE - DID NOT TAKE ACTION'], true);
        }

        if ($expectedSystemStatus === 'PENDING_ANY') {
            return in_array($systemStatus, ['PENDING_HMO', 'PENDING_EMPLOYEE'], true);
        }

        if (in_array($expectedSystemStatus, ['ACTIVE', 'INACTIVE', 'WAIVED', 'TERMINATED', 'INACTIVE - DID NOT TAKE ACTION'], true)) {
            return $systemStatus === $expectedSystemStatus;
        }

        return false;
    }

    private function isCoverageEndDateOnOrAfterToday(string $coverageEndDate): bool
    {
        $today = Carbon::today()->startOfDay();
        $coverageReferenceDate = Carbon::parse($coverageEndDate)->startOfDay();

        return $coverageReferenceDate->greaterThanOrEqualTo($today);
    }

    private function isCoverageEndDateOnOrAfterBillingMonthYear(int $billingMonth, int $billingYear, string $coverageEndDate): bool
    {
        $billingReferenceDate = Carbon::create($billingYear, $billingMonth, 1)->startOfMonth();
        $coverageReferenceDate = Carbon::parse($coverageEndDate)->startOfMonth();

        return $billingReferenceDate->lessThanOrEqualTo($coverageReferenceDate);
    }

    private function normalizeStatusToken(string $status): string
    {
        $normalized = strtoupper(str_replace(['_', ' '], '-', trim($status)));

        return preg_replace('/-+/', '-', $normalized) ?? $normalized;
    }

    private function resolveEffectiveEnrollmentStatusForHealthRecord(object $healthRecord): string
    {
        $rawEnrollmentStatus = (string) ($healthRecord->enrollment_status ?? '');
        $normalizedEnrollmentStatus = strtoupper($rawEnrollmentStatus);

        $dependentId = $healthRecord->dependent_id ?? null;

        if (is_null($dependentId)) {
            return $normalizedEnrollmentStatus;
        }

        $normalizedDependentStatus = strtoupper((string) ($healthRecord->dependent_enrollment_status ?? ''));
        $normalizedPrincipalStatus = strtoupper((string) ($healthRecord->principal_enrollment_status ?? ''));

        $dependentMappedPendingStatus = $this->mapEnrollmentStatusToPendingSystemStatus($normalizedDependentStatus);
        $principalIsActiveEquivalent = $this->isEnrollmentStatusActiveEquivalent($normalizedPrincipalStatus);

        if (!is_null($dependentMappedPendingStatus) && $principalIsActiveEquivalent) {
            return $normalizedPrincipalStatus;
        }

        return $normalizedEnrollmentStatus;
    }

    private function isTerminatedOrResignedStatus(mixed $status): bool
    {
        $normalized = $this->normalizeStatusToken((string) ($status ?? ''));

        return in_array($normalized, ['TERMINATED', 'RESIGNED'], true);
    }

    private function isEnrollmentStatusActiveEquivalent(string $status): bool
    {
        $normalized = $this->normalizeStatusToken($status);

        return in_array($normalized, ['ACTIVE', 'APPROVED', 'AWAITING-DELETION-APPROVAL'], true);
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

    private function resolveResignedSystemStatus(mixed $certificateNumber): string
    {
        $normalizedCertificate = trim((string) ($certificateNumber ?? ''));

        if ($normalizedCertificate !== '') {
            return 'TERMINATED';
        }

        return 'INACTIVE - DID NOT TAKE ACTION';
    }

    private function hasCertificateNumber(mixed $certificateNumber): bool
    {
        return trim((string) ($certificateNumber ?? '')) !== '';
    }

    private function buildModalReconReportRowData(array $rowData): array
    {
        $status = strtoupper(trim((string) ($rowData['SYSTEM_STATUS'] ?? '')));
        $normalizedStatus = $this->normalizeStatusToken($status);

        if ($normalizedStatus === 'APPROVED') {
            $rowData['SYSTEM_STATUS'] = 'NOT_FOUND';
        } else {
            $mappedPendingStatus = $this->mapEnrollmentStatusToPendingSystemStatus($normalizedStatus);

            if ($mappedPendingStatus !== null) {
                $rowData['SYSTEM_STATUS'] = $mappedPendingStatus;
            } elseif ($normalizedStatus === 'RESIGNED') {
                $rowData['SYSTEM_STATUS'] = $this->resolveResignedSystemStatus($rowData['ACCOUNT_NO'] ?? null);
            } elseif ($normalizedStatus === 'OPTED-OUT') {
                $rowData['SYSTEM_STATUS'] = 'WAIVED';
            } elseif (in_array($normalizedStatus, ['ACTIVE', 'INACTIVE', 'TERMINATED', 'WAIVED', 'INACTIVE-DID-NOT-TAKE-ACTION', 'NOT-FOUND'], true)) {
                $rowData['SYSTEM_STATUS'] = $normalizedStatus === 'NOT-FOUND' ? 'NOT_FOUND' : $normalizedStatus;

                if ($normalizedStatus === 'INACTIVE-DID-NOT-TAKE-ACTION') {
                    $rowData['SYSTEM_STATUS'] = 'INACTIVE - DID NOT TAKE ACTION';
                }
            }
        }

        $rowData['DAY(S)'] = $this->computePendingDays((string) ($rowData['SYSTEM_STATUS'] ?? ''), $rowData);

        return $rowData;
    }

    private function computePendingDays(string $systemStatus, array $rowData): string|int
    {
        if ($systemStatus === 'PENDING_EMPLOYEE') {
            $createdAtValue = $this->findRowValueByTokens($rowData, ['createdat']);
            $days = $this->calculateElapsedDaysFromDateValue($createdAtValue);

            return is_null($days) ? '' : $days;
        }

        if ($systemStatus === 'PENDING_HMO') {
            $submissionDateValue = $this->findRowValueByTokens($rowData, ['submissiondate']);
            $days = $this->calculateElapsedDaysFromDateValue($submissionDateValue);

            return is_null($days) ? '' : $days;
        }

        return '';
    }

    private function enrichRowDataWithPrincipalPendingDates(array $rowData, mixed $principalId, mixed $dependentId): array
    {
        $hasCreatedAt = !is_null($this->findRowValueByTokens($rowData, ['createdat']));
        $hasSubmissionDate = !is_null($this->findRowValueByTokens($rowData, ['submissiondate']));

        if ($hasCreatedAt && $hasSubmissionDate) {
            return $rowData;
        }

        $resolvedPrincipalId = !is_null($principalId) ? (int) $principalId : null;

        if (is_null($resolvedPrincipalId) && !is_null($dependentId)) {
            $resolvedPrincipalId = DB::table('cm_dependent')
                ->where('id', (int) $dependentId)
                ->whereNull('deleted_at')
                ->value('principal_id');

            $resolvedPrincipalId = !is_null($resolvedPrincipalId) ? (int) $resolvedPrincipalId : null;
        }

        if (is_null($resolvedPrincipalId) || $resolvedPrincipalId <= 0) {
            return $rowData;
        }

        $principal = DB::table('cm_principal')
            ->where('id', $resolvedPrincipalId)
            ->whereNull('deleted_at')
            ->select(['created_at', 'submission_date'])
            ->first();

        if (!$principal) {
            return $rowData;
        }

        if (!$hasCreatedAt) {
            $rowData['CREATED_AT'] = $this->normalizeDateValue($principal->created_at ?? null);
        }

        if (!$hasSubmissionDate) {
            $rowData['SUBMISSION_DATE'] = $this->normalizeDateValue($principal->submission_date ?? null);
        }

        return $rowData;
    }

    private function findRowValueByTokens(array $rowData, array $candidateTokens): mixed
    {
        foreach ($rowData as $key => $value) {
            $normalizedKey = preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $key))) ?? '';

            if ($normalizedKey !== '' && in_array($normalizedKey, $candidateTokens, true)) {
                return $value;
            }
        }

        return null;
    }

    private function calculateElapsedDaysFromDateValue(mixed $value): ?int
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        try {
            $sourceDate = Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $today = Carbon::today()->startOfDay();
        $days = $sourceDate->diffInDays($today, false);

        if ($days < 0) {
            return 0;
        }

        return $days;
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
                DB::raw('COALESCE(dp.enrollment_status, p.enrollment_status) as principal_enrollment_status'),
                DB::raw('COALESCE(d.enrollment_status, p.enrollment_status) as enrollment_status'),
            ])
            ->orderByDesc('hi.id')
            ->get();

        $seenSystemIdentities = [];
        $reportRows = [];

        foreach ($systemRows as $systemRow) {
            $enrollmentStatus = strtoupper(trim((string) ($systemRow->enrollment_status ?? '')));
            if (!$this->isEnrollmentStatusActiveEquivalent($enrollmentStatus)) {
                continue;
            }

            $isDependent = !is_null($systemRow->dependent_id);
            if ($isDependent && $this->isTerminatedOrResignedStatus($systemRow->principal_enrollment_status ?? null)) {
                continue;
            }

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
                'principal_id' => !is_null($systemRow->principal_id) ? (int) $systemRow->principal_id : null,
                'dependent_id' => !is_null($systemRow->dependent_id) ? (int) $systemRow->dependent_id : null,
                'employee_id' => $employeeId,
                'full_name' => $fullName,
                'relation' => $isDependent ? 'DEPENDENT' : 'PRINCIPAL',
                'birth_date' => $birthDate,
                'enrollment_status' => trim((string) ($systemRow->enrollment_status ?? '')),
                'EFFECTIVE_DATE' => $this->normalizeDateValue($systemRow->coverage_start_date),
                'ACCOUNT_NO' => $systemRow->certificate_number ? trim((string) $systemRow->certificate_number) : null,
                'SYSTEM_STATUS' => 'NOT_FOUND',
            ];
        }

        return $reportRows;
    }

    private function buildReportsFromStoredRecords($records, string $billingType, int $enrollmentId): array
    {
        $reconReport1 = [];
        $reconReport3 = [];

        $importedPrincipalIds = [];
        $importedDependentIds = [];

        foreach ($records as $record) {
            $rowData = $this->buildModalReconReportRowData((array) ($record->row_data ?? []));

            $resolvedStoredStatus = $this->resolveStoredRecordSystemStatus($record, $billingType);
            if (!is_null($resolvedStoredStatus)) {
                $rowData['SYSTEM_STATUS'] = $resolvedStoredStatus;
                $rowData = $this->buildModalReconReportRowData($rowData);
            }

            $status = strtoupper(trim((string) ($rowData['SYSTEM_STATUS'] ?? '')));

            if ($billingType === 'looker_file_validation') {
                $lookerStatusRaw = $this->findRowValueByTokens($rowData, ['status', 'enrollmentstatus', 'memberstatus']);
                $rowData['MATCHED'] = $this->isLookerStatusMatched($status, $lookerStatusRaw);
            }

            $rowData['SOURCE_ROW_NUMBER'] = $record->source_row_number;

            if (in_array($status, ['ACTIVE', 'INACTIVE', 'TERMINATED', 'INACTIVE - DID NOT TAKE ACTION', 'WAIVED', 'NOT_FOUND', 'PENDING_EMPLOYEE', 'PENDING_HMO'], true)) {
                $reconReport1[] = $rowData;
            }

            if (in_array($status, ['PENDING_EMPLOYEE', 'PENDING_HMO'], true)) {
                $reconReport3[] = $rowData;
            }

            if (!is_null($record->principal_id)) {
                $importedPrincipalIds[(int) $record->principal_id] = true;
            }

            if (!is_null($record->dependent_id)) {
                $importedDependentIds[(int) $record->dependent_id] = true;
            }
        }

        $reconReport2 = [];

        if ($billingType === 'modal_billing' || $billingType === 'adjustment_billing') {
            $reconReport2 = $this->buildModalSystemNotFoundReportFromStoredRecords(
                $enrollmentId,
                $importedPrincipalIds,
                $importedDependentIds
            );
        } elseif (in_array($billingType, ['looker_file_validation', 'active_list_validation'], true)) {
            $reconReport2 = $this->buildLookerSystemNotFoundReportFromStoredRecords(
                $enrollmentId,
                $importedPrincipalIds
            );
        }

        return [
            'recon_report_1' => $reconReport1,
            'recon_report_2' => $reconReport2,
            'recon_report_3' => $reconReport3,
        ];
    }

    private function resolveStoredRecordSystemStatus(BillingRecord $record, string $billingType): ?string
    {
        $principalId = $record->principal_id;
        $dependentId = $record->dependent_id;

        if (is_null($principalId) && is_null($dependentId)) {
            return null;
        }

        $healthRecord = DB::table('cm_health_insurance as hi')
            ->leftJoin('cm_principal as p', 'p.id', '=', 'hi.principal_id')
            ->leftJoin('cm_dependent as d', 'd.id', '=', 'hi.dependent_id')
            ->leftJoin('cm_principal as dp', 'dp.id', '=', 'd.principal_id')
            ->where(function ($query) use ($principalId, $dependentId) {
                if (!is_null($principalId)) {
                    $query->orWhere(function ($principalQuery) use ($principalId) {
                        $principalQuery
                            ->whereNotNull('hi.principal_id')
                            ->where('hi.principal_id', $principalId)
                            ->whereNull('p.deleted_at');
                    });
                }

                if (!is_null($dependentId)) {
                    $query->orWhere(function ($dependentQuery) use ($dependentId) {
                        $dependentQuery
                            ->whereNotNull('hi.dependent_id')
                            ->where('hi.dependent_id', $dependentId)
                            ->whereNull('d.deleted_at');
                    });
                }
            })
            ->select([
                'hi.dependent_id',
                'hi.coverage_end_date',
                'hi.certificate_number',
                'd.enrollment_status as dependent_enrollment_status',
                DB::raw('COALESCE(dp.enrollment_status, p.enrollment_status) as principal_enrollment_status'),
                DB::raw('COALESCE(d.enrollment_status, p.enrollment_status) as enrollment_status'),
            ])
            ->orderByDesc('hi.id')
            ->first();

        if (!$healthRecord) {
            return null;
        }

        $resolvedCoverageEndDate = $healthRecord->coverage_end_date
            ? Carbon::parse($healthRecord->coverage_end_date)->format('Y-m-d')
            : null;

        $billingMonth = (int) ($record->billing_month ?? 0);
        $billingYear = (int) ($record->billing_year ?? 0);
        $effectiveEnrollmentStatus = $this->resolveEffectiveEnrollmentStatusForHealthRecord($healthRecord);

        return $this->resolveSystemStatusByBillingContext(
            $billingType,
            $effectiveEnrollmentStatus,
            $healthRecord->certificate_number ?? null,
            $resolvedCoverageEndDate,
            $billingMonth,
            $billingYear
        );
    }

    private function resolveSystemStatusByBillingContext(
        string $billingType,
        string $enrollmentStatus,
        mixed $certificateNumber,
        ?string $coverageEndDate,
        ?int $billingMonth = null,
        ?int $billingYear = null
    ): string {
        $normalizedEnrollmentStatus = strtoupper(trim($enrollmentStatus));
        $normalizedSystemStatus = $this->normalizeStatusToken($normalizedEnrollmentStatus);
        $mappedPendingStatus = $this->mapEnrollmentStatusToPendingSystemStatus($normalizedEnrollmentStatus);
        $hasCertificateNumber = $this->hasCertificateNumber($certificateNumber);

        if ($mappedPendingStatus !== null) {
            return $billingType === 'active_list_validation' ? 'PENDING_EMPLOYEE' : $mappedPendingStatus;
        }

        if ($normalizedSystemStatus === 'RESIGNED') {
            return $this->resolveResignedSystemStatus($certificateNumber);
        }

        if ($normalizedSystemStatus === 'OPTED-OUT') {
            return 'WAIVED';
        }

        if ($billingType === 'active_list_validation') {
            if (!$hasCertificateNumber && $this->isEnrollmentStatusActiveEquivalent($normalizedSystemStatus)) {
                return 'PENDING_HMO';
            }

            if ($this->isEnrollmentStatusActiveEquivalent($normalizedSystemStatus)) {
                return 'ACTIVE';
            }

            return !is_null($coverageEndDate) ? 'INACTIVE' : 'ACTIVE';
        }

        if ($billingType === 'looker_file_validation' && $normalizedSystemStatus === 'AWAITING-DELETION-APPROVAL') {
            return 'ACTIVE';
        }

        if (!$hasCertificateNumber && $this->isEnrollmentStatusActiveEquivalent($normalizedSystemStatus)) {
            return 'PENDING_HMO';
        }

        if ($this->isEnrollmentStatusActiveEquivalent($normalizedSystemStatus) && is_null($coverageEndDate)) {
            return 'ACTIVE';
        }

        if ($billingType === 'looker_file_validation') {
            if ($coverageEndDate && $this->isCoverageEndDateOnOrAfterToday($coverageEndDate)) {
                return $hasCertificateNumber ? 'ACTIVE' : 'PENDING_HMO';
            }

            return 'INACTIVE';
        }

        if (
            $coverageEndDate &&
            !is_null($billingMonth) &&
            !is_null($billingYear) &&
            $billingMonth >= 1 &&
            $billingMonth <= 12 &&
            $billingYear >= 2000 &&
            $this->isCoverageEndDateOnOrAfterBillingMonthYear($billingMonth, $billingYear, $coverageEndDate)
        ) {
            return $hasCertificateNumber ? 'ACTIVE' : 'PENDING_HMO';
        }

        return 'INACTIVE';
    }

    private function buildModalSystemNotFoundReportFromStoredRecords(
        int $enrollmentId,
        array $importedPrincipalIds,
        array $importedDependentIds
    ): array {
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
                DB::raw('COALESCE(dp.enrollment_status, p.enrollment_status) as principal_enrollment_status'),
                DB::raw('COALESCE(d.enrollment_status, p.enrollment_status) as enrollment_status'),
            ])
            ->orderByDesc('hi.id')
            ->get();

        $seenSystemRows = [];
        $reportRows = [];

        foreach ($systemRows as $systemRow) {
            $enrollmentStatus = strtoupper(trim((string) ($systemRow->enrollment_status ?? '')));
            if (!$this->isEnrollmentStatusActiveEquivalent($enrollmentStatus)) {
                continue;
            }

            $principalId = !is_null($systemRow->principal_id) ? (int) $systemRow->principal_id : null;
            $dependentId = !is_null($systemRow->dependent_id) ? (int) $systemRow->dependent_id : null;

            if (is_null($principalId) && is_null($dependentId)) {
                continue;
            }

            $isDependent = !is_null($dependentId);
            if ($isDependent && $this->isTerminatedOrResignedStatus($systemRow->principal_enrollment_status ?? null)) {
                continue;
            }

            $identityKey = 'P:' . ($principalId ?? 0) . '|D:' . ($dependentId ?? 0);
            if (isset($seenSystemRows[$identityKey])) {
                continue;
            }
            $seenSystemRows[$identityKey] = true;

            if (!is_null($dependentId) && isset($importedDependentIds[$dependentId])) {
                continue;
            }

            if (is_null($dependentId) && !is_null($principalId) && isset($importedPrincipalIds[$principalId])) {
                continue;
            }

            $employeeId = $this->normalizeIdentityValue(
                $isDependent ? $systemRow->dependent_employee_id : $systemRow->principal_employee_id
            );
            $birthDate = $this->normalizeDateValue(
                $isDependent ? $systemRow->dependent_birth_date : $systemRow->principal_birth_date
            );

            $fullName = $this->buildPersonFullName(
                $isDependent ? $systemRow->dependent_first_name : $systemRow->principal_first_name,
                $isDependent ? $systemRow->dependent_middle_name : $systemRow->principal_middle_name,
                $isDependent ? $systemRow->dependent_last_name : $systemRow->principal_last_name,
                $isDependent ? $systemRow->dependent_suffix : $systemRow->principal_suffix
            );

            $reportRows[] = [
                'principal_id' => $principalId,
                'dependent_id' => $dependentId,
                'employee_id' => $employeeId !== '' ? $employeeId : null,
                'full_name' => $fullName,
                'relation' => $isDependent ? 'DEPENDENT' : 'PRINCIPAL',
                'birth_date' => $birthDate,
                'enrollment_status' => trim((string) ($systemRow->enrollment_status ?? '')),
                'EFFECTIVE_DATE' => $this->normalizeDateValue($systemRow->coverage_start_date),
                'ACCOUNT_NO' => $systemRow->certificate_number ? trim((string) $systemRow->certificate_number) : null,
                'SYSTEM_STATUS' => 'NOT_FOUND',
            ];
        }

        return $reportRows;
    }

    private function resolveReconReport2SoftDeleteTarget(
        int $enrollmentId,
        string $relation,
        ?int $principalId,
        ?int $dependentId,
        string $employeeId,
        ?string $birthDate
    ): ?array {
        if (!is_null($dependentId) && $dependentId > 0) {
            $dependent = Dependent::query()
                ->where('id', $dependentId)
                ->whereNull('deleted_at')
                ->whereHas('principal', function ($query) use ($enrollmentId) {
                    $query
                        ->where('enrollment_id', $enrollmentId)
                        ->whereNull('deleted_at');
                })
                ->first();

            if ($dependent) {
                return [
                    'type' => 'dependent',
                    'model' => $dependent,
                    'principal_id' => (int) $dependent->principal_id,
                    'dependent_id' => (int) $dependent->id,
                ];
            }
        }

        if (!is_null($principalId) && $principalId > 0) {
            $principal = Enrollee::query()
                ->where('id', $principalId)
                ->where('enrollment_id', $enrollmentId)
                ->whereNull('deleted_at')
                ->first();

            if ($principal) {
                return [
                    'type' => 'principal',
                    'model' => $principal,
                    'principal_id' => (int) $principal->id,
                    'dependent_id' => null,
                ];
            }
        }

        if ($employeeId === '') {
            return null;
        }

        if ($relation === 'DEPENDENT') {
            $dependentQuery = Dependent::query()
                ->whereNull('deleted_at')
                ->whereRaw('UPPER(TRIM(employee_id)) = ?', [$employeeId])
                ->whereHas('principal', function ($query) use ($enrollmentId) {
                    $query
                        ->where('enrollment_id', $enrollmentId)
                        ->whereNull('deleted_at');
                });

            if (!is_null($birthDate)) {
                $dependentQuery->whereDate('birth_date', $birthDate);
            }

            $dependent = $dependentQuery->orderByDesc('id')->first();

            if ($dependent) {
                return [
                    'type' => 'dependent',
                    'model' => $dependent,
                    'principal_id' => (int) $dependent->principal_id,
                    'dependent_id' => (int) $dependent->id,
                ];
            }
        }

        $principalQuery = Enrollee::query()
            ->where('enrollment_id', $enrollmentId)
            ->whereNull('deleted_at')
            ->whereRaw('UPPER(TRIM(employee_id)) = ?', [$employeeId]);

        if (!is_null($birthDate)) {
            $principalQuery->whereDate('birth_date', $birthDate);
        }

        $principal = $principalQuery->orderByDesc('id')->first();

        if ($principal) {
            return [
                'type' => 'principal',
                'model' => $principal,
                'principal_id' => (int) $principal->id,
                'dependent_id' => null,
            ];
        }

        return null;
    }

    private function buildLookerSystemNotFoundReport(
        int $enrollmentId,
        array $uploadedRows,
        string $employeeIdColumnKey
    ): array {
        $uploadedEmployeeIds = [];

        foreach ($uploadedRows as $row) {
            $rowData = $row['row_data'] ?? [];
            $employeeId = $this->normalizeIdentityValue($rowData[$employeeIdColumnKey] ?? null);

            if ($employeeId === '') {
                continue;
            }

            $uploadedEmployeeIds[$employeeId] = true;
        }

        $systemRows = DB::table('cm_principal as p')
            ->leftJoin('cm_health_insurance as hi', 'hi.principal_id', '=', 'p.id')
            ->where('p.enrollment_id', $enrollmentId)
            ->whereNull('p.deleted_at')
            ->select([
                'p.id as principal_id',
                'p.employee_id as principal_employee_id',
                'p.first_name as principal_first_name',
                'p.middle_name as principal_middle_name',
                'p.last_name as principal_last_name',
                'p.suffix as principal_suffix',
                'p.birth_date as principal_birth_date',
                'p.enrollment_status as enrollment_status',
                'hi.coverage_start_date',
                'hi.certificate_number',
            ])
            ->orderByRaw('hi.id IS NULL ASC')
            ->orderByDesc('hi.id')
            ->get();

        $seenSystemEmployeeIds = [];
        $reportRows = [];

        foreach ($systemRows as $systemRow) {
            $enrollmentStatus = strtoupper(trim((string) ($systemRow->enrollment_status ?? '')));
            if (!$this->isEnrollmentStatusActiveEquivalent($enrollmentStatus)) {
                continue;
            }

            $employeeId = $this->normalizeIdentityValue($systemRow->principal_employee_id ?? null);
            if ($employeeId === '') {
                continue;
            }

            if (isset($seenSystemEmployeeIds[$employeeId])) {
                continue;
            }
            $seenSystemEmployeeIds[$employeeId] = true;

            if (isset($uploadedEmployeeIds[$employeeId])) {
                continue;
            }

            $fullName = $this->buildPersonFullName(
                $systemRow->principal_first_name,
                $systemRow->principal_middle_name,
                $systemRow->principal_last_name,
                $systemRow->principal_suffix
            );

            $reportRows[] = [
                'principal_id' => !is_null($systemRow->principal_id) ? (int) $systemRow->principal_id : null,
                'dependent_id' => null,
                'employee_id' => $employeeId,
                'full_name' => $fullName,
                'relation' => 'PRINCIPAL',
                'birth_date' => $this->normalizeDateValue($systemRow->principal_birth_date),
                'enrollment_status' => trim((string) ($systemRow->enrollment_status ?? '')),
                'EFFECTIVE_DATE' => $this->normalizeDateValue($systemRow->coverage_start_date),
                'ACCOUNT_NO' => $systemRow->certificate_number ? trim((string) $systemRow->certificate_number) : null,
                'SYSTEM_STATUS' => 'NOT_FOUND',
            ];
        }

        return $reportRows;
    }

    private function buildLookerSystemNotFoundReportFromStoredRecords(
        int $enrollmentId,
        array $importedPrincipalIds
    ): array {
        $systemRows = DB::table('cm_principal as p')
            ->leftJoin('cm_health_insurance as hi', 'hi.principal_id', '=', 'p.id')
            ->where('p.enrollment_id', $enrollmentId)
            ->whereNull('p.deleted_at')
            ->select([
                'p.id as principal_id',
                'p.employee_id as principal_employee_id',
                'p.first_name as principal_first_name',
                'p.middle_name as principal_middle_name',
                'p.last_name as principal_last_name',
                'p.suffix as principal_suffix',
                'p.birth_date as principal_birth_date',
                'p.enrollment_status as enrollment_status',
                'hi.coverage_start_date',
                'hi.certificate_number',
            ])
            ->orderByRaw('hi.id IS NULL ASC')
            ->orderByDesc('hi.id')
            ->get();

        $seenPrincipalIds = [];
        $reportRows = [];

        foreach ($systemRows as $systemRow) {
            $enrollmentStatus = strtoupper(trim((string) ($systemRow->enrollment_status ?? '')));
            if (!$this->isEnrollmentStatusActiveEquivalent($enrollmentStatus)) {
                continue;
            }

            $principalId = !is_null($systemRow->principal_id) ? (int) $systemRow->principal_id : null;
            if (is_null($principalId)) {
                continue;
            }

            if (isset($seenPrincipalIds[$principalId])) {
                continue;
            }
            $seenPrincipalIds[$principalId] = true;

            if (isset($importedPrincipalIds[$principalId])) {
                continue;
            }

            $fullName = $this->buildPersonFullName(
                $systemRow->principal_first_name,
                $systemRow->principal_middle_name,
                $systemRow->principal_last_name,
                $systemRow->principal_suffix
            );

            $employeeId = $this->normalizeIdentityValue($systemRow->principal_employee_id ?? null);

            $reportRows[] = [
                'principal_id' => $principalId,
                'dependent_id' => null,
                'employee_id' => $employeeId !== '' ? $employeeId : null,
                'full_name' => $fullName,
                'relation' => 'PRINCIPAL',
                'birth_date' => $this->normalizeDateValue($systemRow->principal_birth_date),
                'enrollment_status' => trim((string) ($systemRow->enrollment_status ?? '')),
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