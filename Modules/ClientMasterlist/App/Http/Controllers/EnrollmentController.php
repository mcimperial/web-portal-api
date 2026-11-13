<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Enrollment;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Dependent;
use Modules\ClientMasterlist\App\Models\HealthInsurance;
use Modules\ClientMasterlist\App\Models\Attachment;
use App\Http\Traits\PasswordDeleteValidation;
use App\Http\Traits\LogsActions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Modules\ClientMasterlist\App\Http\Middleware\EnsureEnrollmentAccess;
use Modules\ClientMasterlist\App\Http\Requests\Enrollments\AssignEnrollmentUserRequest;
use Modules\ClientMasterlist\App\Http\Requests\Enrollments\StoreEnrollmentRequest;
use Modules\ClientMasterlist\App\Http\Requests\Enrollments\UpdateEnrollmentRequest;
use Modules\ClientMasterlist\App\Models\UserCM;
use Modules\ClientMasterlist\App\Models\EnrollmentRole;
use Modules\ClientMasterlist\App\Models\EnrollmentSyncLog;
use Modules\ClientMasterlist\App\Services\Enrollments\EnrollmentAccessService;
use Modules\ClientMasterlist\App\Services\Enrollments\EnrollmentPayloadService;

class EnrollmentController extends Controller
{
    use PasswordDeleteValidation, LogsActions;

    public function __construct(
        private readonly EnrollmentPayloadService $payloadService,
        private readonly EnrollmentAccessService $accessService,
    ) {
        $this->middleware(EnsureEnrollmentAccess::class)->only([
            'show',
            'getEnrollmentAccess',
            'update',
            'destroy',
            'compareSync',
            'syncToEnrollment',
            'rollbackSync',
            'getSyncHistory',
        ]);
    }

    public function index()
    {
        $user = request()->user();
        $ids = request()->query('ids');
        $isadmin = $this->accessService->isAdmin($user);

        if (!$isadmin) {
            $assigned = EnrollmentRole::where('user_id', $user->id)
                ->pluck('enrollment_id')
                ->filter()
                ->values()
                ->all();

            $requested = array_filter(array_map('intval', explode(',', (string) $ids)));
            $idArray = empty($requested)
                ? $assigned
                : array_values(array_intersect($assigned, $requested));

            return Enrollment::whereIn('id', $idArray)->get();
        }

        return Enrollment::all();
    }

    public function store(StoreEnrollmentRequest $request)
    {
        $payload = $this->payloadService->prepare($request->validated(), $request);
        $enrollment = Enrollment::create($payload);
        
        // Log the create action
        $this->logCreate($enrollment, ['request_data' => $request->all()]);
        
        return response()->json($enrollment, 201);
    }

    public function show($id)
    {
        return Enrollment::findOrFail($id);
    }

    /**
     * Get user permissions for a specific enrollment
     */
    public function getEnrollmentAccess(Request $request, $id)
    {
        return response()->json($this->accessService->accessPayload($request->user()));
    }

    public function update(UpdateEnrollmentRequest $request, $id)
    {
        $enrollment = Enrollment::findOrFail($id);
        $oldValues = $enrollment->toArray();
        $payload = $this->payloadService->prepare($request->validated(), $request);
        $enrollment->update($payload);
        
        // Log the update action
        $this->logUpdate($enrollment, $oldValues, ['request_data' => $request->all()]);
        
        return response()->json($enrollment);
    }

    public function destroy(Request $request, $id)
    {
        $user = $this->validateDeletePassword($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $awaitingDeletionApproval = $request->boolean('awaiting_deletion_approval', true);
        $enrollment = Enrollment::findOrFail($id);

        if ($awaitingDeletionApproval) {
            $oldValues = $enrollment->toArray();

            if (Schema::hasColumn($enrollment->getTable(), 'temp_end_date')) {
                $enrollment->temp_end_date = now();
            }

            if (Schema::hasColumn($enrollment->getTable(), 'status')) {
                $enrollment->status = 'awaiting-deletion-approval';
            }

            if (Schema::hasColumn($enrollment->getTable(), 'deleted_by')) {
                $enrollment->deleted_by = $user ? $user->id : null;
            }

            $enrollment->save();

            $this->logUpdate($enrollment, $oldValues, [
                'action' => 'awaiting_deletion_approval',
                'awaiting_deletion_approval' => true,
                'deleted_by' => $user ? $user->id : null,
            ]);

            return response()->json([
                'message' => 'Marked as awaiting deletion approval',
            ]);
        }

        // If the model has a deleted_by column, set it
        if (Schema::hasColumn($enrollment->getTable(), 'deleted_by')) {
            $enrollment->deleted_by = $user ? $user->id : null;
            $enrollment->save();
        }
        
        // Log the delete action
        $this->logDelete($enrollment, ['deleted_by' => $user ? $user->id : null]);
        
        $enrollment->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }

    public function getUsers()
    {
        return UserCM::all();
    }

    public function getEnrollmentRoles()
    {
        return EnrollmentRole::with(['user', 'enrollment'])->get();
    }

    public function assignUserToEnrollment(AssignEnrollmentUserRequest $request)
    {
        $validated = $request->validated();

        // Check if the assignment already exists
        $existing = EnrollmentRole::where('user_id', $validated['user_id'])
            ->where('enrollment_id', $validated['enrollment_id'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'User is already assigned to this enrollment'], 409);
        }

        $assignment = EnrollmentRole::create($validated);
        
        // Log the assignment
        $this->logCreate($assignment, ['action' => 'assign_user_to_enrollment']);
        
        return response()->json($assignment, 201);
    }

    public function removeUserFromEnrollment(Request $request, $id)
    {
        $user = $this->validateDeletePassword($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }

        $assignment = EnrollmentRole::findOrFail($id);
        
        // Log the removal
        $this->logDelete($assignment, ['action' => 'remove_user_from_enrollment']);
        
        $assignment->delete();
        return response()->json(['message' => 'User removed from enrollment successfully']);
    }

    public function compareSync(Request $request, $id)
    {
        $validated = $request->validate([
            'target_enrollment_id' => 'required|integer|exists:cm_enrollment,id',
        ]);

        $sourceEnrollment = Enrollment::findOrFail((int) $id);
        $targetEnrollment = Enrollment::findOrFail((int) $validated['target_enrollment_id']);

        if ((int) $sourceEnrollment->id === (int) $targetEnrollment->id) {
            return response()->json([
                'message' => 'Source and target enrollment must be different.',
            ], 422);
        }

        $sourcePrincipals = Enrollee::query()
            ->with(['healthInsurance', 'dependents.healthInsurance'])
            ->where('enrollment_id', $sourceEnrollment->id)
            ->get();

        $targetPrincipals = Enrollee::query()
            ->with(['healthInsurance'])
            ->where('enrollment_id', $targetEnrollment->id)
            ->get();

        $targetPrincipalsByEmployee = $targetPrincipals
            ->keyBy(fn (Enrollee $item) => mb_strtoupper(trim((string) $item->employee_id)));

        $sourceDependentIds = $sourcePrincipals
            ->flatMap(fn (Enrollee $principal) => $principal->dependents->pluck('id'))
            ->filter()
            ->values();

        $sourceAttachmentRows = Attachment::query()
            ->whereIn('principal_id', $sourcePrincipals->pluck('id')->all())
            ->orWhereIn('dependent_id', $sourceDependentIds->all())
            ->get(['id', 'principal_id', 'dependent_id']);

        $attachmentCountByPrincipal = [];
        $dependentPrincipalMap = $sourcePrincipals
            ->flatMap(function (Enrollee $principal) {
                return $principal->dependents->mapWithKeys(fn (Dependent $dependent) => [
                    (int) $dependent->id => (int) $principal->id,
                ]);
            })
            ->all();

        foreach ($sourceAttachmentRows as $row) {
            $principalId = $row->principal_id ? (int) $row->principal_id : null;
            if (!$principalId && $row->dependent_id) {
                $principalId = $dependentPrincipalMap[(int) $row->dependent_id] ?? null;
            }
            if (!$principalId) {
                continue;
            }
            $attachmentCountByPrincipal[$principalId] = ($attachmentCountByPrincipal[$principalId] ?? 0) + 1;
        }

        $columnsToCompare = $this->columnsForCompare();
        $matchedComparisons = [];
        $skipResignedOrAwaiting = [];
        $transferableCount = 0;

        foreach ($sourcePrincipals as $sourcePrincipal) {
            $employeeKey = mb_strtoupper(trim((string) $sourcePrincipal->employee_id));
            if ($employeeKey === '') {
                continue;
            }

            /** @var Enrollee|null $targetPrincipal */
            $targetPrincipal = $targetPrincipalsByEmployee->get($employeeKey);

            if (!$targetPrincipal) {
                $transferableCount++;
                continue;
            }

            $targetStatus = mb_strtoupper(trim((string) ($targetPrincipal->enrollment_status ?: $targetPrincipal->status)));
            if ($targetStatus === 'RESIGNED' || $targetStatus === 'AWAITING-DELETION-APPROVAL') {
                $skipResignedOrAwaiting[] = [
                    'employee_id' => $sourcePrincipal->employee_id,
                    'target_principal_id' => $targetPrincipal->id,
                    'target_status' => $targetStatus,
                ];
                continue;
            }

            $diffs = [];
            foreach ($columnsToCompare as $column) {
                $sourceValue = $sourcePrincipal->{$column};
                $targetValue = $targetPrincipal->{$column};
                if ((string) $sourceValue !== (string) $targetValue) {
                    $diffs[] = [
                        'column' => $column,
                        'source' => $sourceValue,
                        'target' => $targetValue,
                    ];
                }
            }

            $matchedComparisons[] = [
                'employee_id' => $sourcePrincipal->employee_id,
                'source_principal_id' => $sourcePrincipal->id,
                'target_principal_id' => $targetPrincipal->id,
                'document_count_source' => $attachmentCountByPrincipal[(int) $sourcePrincipal->id] ?? 0,
                'diff_count' => count($diffs),
                'diffs' => $diffs,
            ];
        }

        $sourceDependents = $sourcePrincipals
            ->flatMap(fn (Enrollee $principal) => $principal->dependents)
            ->values();

        $targetDependents = Dependent::query()
            ->with(['healthInsurance', 'principal'])
            ->whereIn('principal_id', $targetPrincipals->pluck('id')->all())
            ->get();

        $principalCompareDetails = $this->composeCompareRows($sourcePrincipals, $targetPrincipals, 'principal');
        $dependentCompareDetails = $this->composeCompareRows($sourceDependents, $targetDependents, 'dependent');

        return response()->json([
            'source_enrollment' => [
                'id' => $sourceEnrollment->id,
                'title' => $sourceEnrollment->title,
            ],
            'target_enrollment' => [
                'id' => $targetEnrollment->id,
                'title' => $targetEnrollment->title,
            ],
            'summary' => [
                'source_principals' => $sourcePrincipals->count(),
                'matched_in_target' => count($matchedComparisons),
                'transferable_principals' => $transferableCount,
                'skipped_resigned_or_awaiting' => count($skipResignedOrAwaiting),
                'columns_compared' => $columnsToCompare,
            ],
            'matched' => $matchedComparisons,
            'skipped' => [
                'resigned_or_awaiting' => $skipResignedOrAwaiting,
            ],
            'compare_details' => [
                'principals' => $principalCompareDetails,
                'dependents' => $dependentCompareDetails,
            ],
        ]);
    }

    public function getSyncHistory(Request $request, $id)
    {
        $sourceEnrollment = Enrollment::findOrFail((int) $id);
        $limit = min(max((int) $request->query('per_page', 20), 1), 100);

        $history = EnrollmentSyncLog::query()
            ->where('source_enrollment_id', $sourceEnrollment->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json($history);
    }

    public function syncToEnrollment(Request $request, $id)
    {
        $validated = $request->validate([
            'target_enrollment_id' => 'required|integer|exists:cm_enrollment,id',
        ]);

        $sourceEnrollment = Enrollment::findOrFail((int) $id);
        $targetEnrollment = Enrollment::findOrFail((int) $validated['target_enrollment_id']);

        if ((int) $sourceEnrollment->id === (int) $targetEnrollment->id) {
            return response()->json([
                'message' => 'Source and target enrollment must be different.',
            ], 422);
        }

        $result = DB::transaction(function () use ($request, $sourceEnrollment, $targetEnrollment) {
            $sourcePrincipals = Enrollee::query()
                ->with(['healthInsurance', 'dependents.healthInsurance'])
                ->where('enrollment_id', $sourceEnrollment->id)
                ->get();

            $targetPrincipals = Enrollee::query()
                ->where('enrollment_id', $targetEnrollment->id)
                ->get()
                ->keyBy(fn (Enrollee $item) => mb_strtoupper(trim((string) $item->employee_id)));

            $createdPrincipalIds = [];
            $createdDependentIds = [];
            $createdHealthInsuranceIds = [];
            $createdAttachmentIds = [];
            $updatedPrincipalCount = 0;
            $updatedDependentCount = 0;
            $deletedDependentCount = 0;

            $skippedExisting = [];
            $skippedResignedOrAwaiting = [];

            foreach ($sourcePrincipals as $sourcePrincipal) {
                $employeeKey = mb_strtoupper(trim((string) $sourcePrincipal->employee_id));

                if ($employeeKey !== '' && $targetPrincipals->has($employeeKey)) {
                    /** @var Enrollee $existingTarget */
                    $existingTarget = $targetPrincipals->get($employeeKey);
                    $targetStatus = mb_strtoupper(trim((string) ($existingTarget->enrollment_status ?: $existingTarget->status)));

                    if ($targetStatus === 'RESIGNED' || $targetStatus === 'AWAITING-DELETION-APPROVAL') {
                        $skippedResignedOrAwaiting[] = [
                            'employee_id' => $sourcePrincipal->employee_id,
                            'target_principal_id' => $existingTarget->id,
                            'target_status' => $targetStatus,
                        ];

                        continue;
                    }

                    $updatedPrincipalCount += $this->syncPrincipalFromSourceToTarget(
                        $sourcePrincipal,
                        $existingTarget
                    );

                    $this->syncPrincipalHealthInsuranceFromSource(
                        $sourcePrincipal,
                        $existingTarget,
                        $createdHealthInsuranceIds
                    );

                    $this->syncPrincipalAttachmentsFromSource(
                        $sourcePrincipal,
                        $existingTarget,
                        $createdAttachmentIds
                    );

                    $dependentSyncResult = $this->syncDependentsFromSourceToTarget(
                        $sourcePrincipal,
                        $existingTarget,
                        $createdDependentIds,
                        $createdHealthInsuranceIds,
                        $createdAttachmentIds
                    );

                    $updatedDependentCount += $dependentSyncResult['updated_dependents'];
                    $deletedDependentCount += $dependentSyncResult['deleted_dependents'];

                    continue;
                }

                $principalData = Arr::only($sourcePrincipal->toArray(), (new Enrollee())->getFillable());
                $principalData['enrollment_id'] = $targetEnrollment->id;
                unset($principalData['uuid']);

                $createdPrincipal = Enrollee::create($principalData);
                $createdPrincipalIds[] = (int) $createdPrincipal->id;

                if ($sourcePrincipal->healthInsurance) {
                    $this->syncPrincipalHealthInsuranceFromSource(
                        $sourcePrincipal,
                        $createdPrincipal,
                        $createdHealthInsuranceIds
                    );
                }

                $this->syncPrincipalAttachmentsFromSource(
                    $sourcePrincipal,
                    $createdPrincipal,
                    $createdAttachmentIds
                );

                $dependentSyncResult = $this->syncDependentsFromSourceToTarget(
                    $sourcePrincipal,
                    $createdPrincipal,
                    $createdDependentIds,
                    $createdHealthInsuranceIds,
                    $createdAttachmentIds
                );

                $updatedDependentCount += $dependentSyncResult['updated_dependents'];
                $deletedDependentCount += $dependentSyncResult['deleted_dependents'];
            }

            $summary = [
                'created_principals' => count($createdPrincipalIds),
                'updated_principals' => $updatedPrincipalCount,
                'created_dependents' => count($createdDependentIds),
                'updated_dependents' => $updatedDependentCount,
                'deleted_dependents' => $deletedDependentCount,
                'created_health_insurance' => count($createdHealthInsuranceIds),
                'created_attachments' => count($createdAttachmentIds),
                'skipped_existing' => count($skippedExisting),
                'skipped_resigned_or_awaiting' => count($skippedResignedOrAwaiting),
            ];

            $rollbackPayload = [
                'source_enrollment_id' => (int) $sourceEnrollment->id,
                'target_enrollment_id' => (int) $targetEnrollment->id,
                'principal_ids' => $createdPrincipalIds,
                'dependent_ids' => $createdDependentIds,
                'health_insurance_ids' => $createdHealthInsuranceIds,
                'attachment_ids' => $createdAttachmentIds,
            ];

            $syncLog = EnrollmentSyncLog::create([
                'source_enrollment_id' => (int) $sourceEnrollment->id,
                'target_enrollment_id' => (int) $targetEnrollment->id,
                'initiated_by' => optional($request->user())->id,
                'status' => 'completed',
                'summary' => $summary,
                'skipped' => [
                    'existing' => $skippedExisting,
                    'resigned_or_awaiting' => $skippedResignedOrAwaiting,
                ],
                'rollback_payload' => $rollbackPayload,
            ]);

            return [
                'source_enrollment_id' => (int) $sourceEnrollment->id,
                'target_enrollment_id' => (int) $targetEnrollment->id,
                'created_principal_ids' => $createdPrincipalIds,
                'created_dependent_ids' => $createdDependentIds,
                'created_health_insurance_ids' => $createdHealthInsuranceIds,
                'created_attachment_ids' => $createdAttachmentIds,
                'skipped_existing' => $skippedExisting,
                'skipped_resigned_or_awaiting' => $skippedResignedOrAwaiting,
                'summary' => $summary,
                'sync_log_id' => (int) $syncLog->id,
                'rollback_payload' => $rollbackPayload,
            ];
        });

        return response()->json([
            'message' => 'Enrollment sync completed.',
            'sync_log_id' => $result['sync_log_id'],
            'summary' => $result['summary'],
            'skipped' => [
                'existing' => $result['skipped_existing'],
                'resigned_or_awaiting' => $result['skipped_resigned_or_awaiting'],
            ],
            'rollback_payload' => $result['rollback_payload'],
        ]);
    }

    private function syncDependentsFromSourceToTarget(
        Enrollee $sourcePrincipal,
        Enrollee $targetPrincipal,
        array &$createdDependentIds,
        array &$createdHealthInsuranceIds,
        array &$createdAttachmentIds
    ): array {
        $sourceDependents = Dependent::query()
            ->with('healthInsurance')
            ->where('principal_id', $sourcePrincipal->id)
            ->whereNull('deleted_at')
            ->get();

        $targetDependents = Dependent::query()
            ->with('healthInsurance')
            ->where('principal_id', $targetPrincipal->id)
            ->whereNull('deleted_at')
            ->get();

        $targetBuckets = [];
        foreach ($targetDependents as $targetDependent) {
            $signature = $this->dependentSignature($targetDependent);
            if (!isset($targetBuckets[$signature])) {
                $targetBuckets[$signature] = [];
            }
            $targetBuckets[$signature][] = $targetDependent;
        }

        $matchedTargetIds = [];
        $updatedDependentCount = 0;
        $deletedDependentCount = 0;

        foreach ($sourceDependents as $sourceDependent) {
            $signature = $this->dependentSignature($sourceDependent);
            $matchedTarget = null;

            if (isset($targetBuckets[$signature]) && count($targetBuckets[$signature]) > 0) {
                $matchedTarget = array_shift($targetBuckets[$signature]);
            }

            if ($matchedTarget) {
                $dependentData = Arr::only($sourceDependent->toArray(), (new Dependent())->getFillable());
                $dependentData['principal_id'] = $targetPrincipal->id;

                $matchedTarget->fill($dependentData);
                if ($matchedTarget->isDirty()) {
                    $matchedTarget->save();
                    $updatedDependentCount++;
                }

                $matchedTargetIds[] = (int) $matchedTarget->id;

                $this->syncDependentHealthInsuranceFromSource(
                    $sourceDependent,
                    $targetPrincipal,
                    $matchedTarget,
                    $createdHealthInsuranceIds
                );

                $this->syncDependentAttachmentsFromSource(
                    $sourceDependent,
                    $targetPrincipal,
                    $matchedTarget,
                    $createdAttachmentIds
                );

                continue;
            }

            $dependentData = Arr::only($sourceDependent->toArray(), (new Dependent())->getFillable());
            $dependentData['principal_id'] = $targetPrincipal->id;

            $createdDependent = Dependent::create($dependentData);
            $createdDependentIds[] = (int) $createdDependent->id;
            $matchedTargetIds[] = (int) $createdDependent->id;

            $this->syncDependentHealthInsuranceFromSource(
                $sourceDependent,
                $targetPrincipal,
                $createdDependent,
                $createdHealthInsuranceIds
            );

            $this->syncDependentAttachmentsFromSource(
                $sourceDependent,
                $targetPrincipal,
                $createdDependent,
                $createdAttachmentIds
            );
        }

        $targetOnlyDependents = $targetDependents
            ->filter(fn (Dependent $dependent) => !in_array((int) $dependent->id, $matchedTargetIds, true))
            ->values();

        foreach ($targetOnlyDependents as $targetOnlyDependent) {
            Attachment::query()->where('dependent_id', $targetOnlyDependent->id)->delete();
            HealthInsurance::query()->where('dependent_id', $targetOnlyDependent->id)->delete();
            $targetOnlyDependent->delete();
            $deletedDependentCount++;
        }

        return [
            'updated_dependents' => $updatedDependentCount,
            'deleted_dependents' => $deletedDependentCount,
        ];
    }

    private function syncDependentHealthInsuranceFromSource(
        Dependent $sourceDependent,
        Enrollee $targetPrincipal,
        Dependent $targetDependent,
        array &$createdHealthInsuranceIds
    ): void {
        if (!$sourceDependent->healthInsurance) {
            HealthInsurance::query()
                ->where('dependent_id', $targetDependent->id)
                ->delete();
            return;
        }

        $healthData = Arr::only(
            $sourceDependent->healthInsurance->toArray(),
            (new HealthInsurance())->getFillable()
        );
        $healthData['principal_id'] = $targetPrincipal->id;
        $healthData['dependent_id'] = $targetDependent->id;

        $targetHealth = HealthInsurance::query()
            ->where('dependent_id', $targetDependent->id)
            ->first();

        if ($targetHealth) {
            $targetHealth->fill($healthData);
            $targetHealth->certificate_number = $sourceDependent->healthInsurance->certificate_number;
            $targetHealth->certificate_date_issued = $sourceDependent->healthInsurance->certificate_date_issued;
            if ($targetHealth->isDirty()) {
                $targetHealth->save();
            }
            return;
        }

        $createdHealthInsurance = HealthInsurance::create($healthData);
        $createdHealthInsuranceIds[] = (int) $createdHealthInsurance->id;
    }

    private function syncPrincipalFromSourceToTarget(
        Enrollee $sourcePrincipal,
        Enrollee $targetPrincipal
    ): int {
        $principalData = Arr::only($sourcePrincipal->toArray(), (new Enrollee())->getFillable());
        $principalData['enrollment_id'] = $targetPrincipal->enrollment_id;
        unset($principalData['uuid']);

        $targetPrincipal->fill($principalData);
        if ($targetPrincipal->isDirty()) {
            $targetPrincipal->save();
            return 1;
        }

        return 0;
    }

    private function syncPrincipalHealthInsuranceFromSource(
        Enrollee $sourcePrincipal,
        Enrollee $targetPrincipal,
        array &$createdHealthInsuranceIds
    ): void {
        if (!$sourcePrincipal->healthInsurance) {
            HealthInsurance::query()
                ->where('principal_id', $targetPrincipal->id)
                ->whereNull('dependent_id')
                ->delete();
            return;
        }

        $healthData = Arr::only(
            $sourcePrincipal->healthInsurance->toArray(),
            (new HealthInsurance())->getFillable()
        );
        $healthData['principal_id'] = $targetPrincipal->id;
        $healthData['dependent_id'] = null;

        $targetHealth = HealthInsurance::query()
            ->where('principal_id', $targetPrincipal->id)
            ->whereNull('dependent_id')
            ->first();

        if ($targetHealth) {
            $targetHealth->fill($healthData);
            $targetHealth->certificate_number = $sourcePrincipal->healthInsurance->certificate_number;
            $targetHealth->certificate_date_issued = $sourcePrincipal->healthInsurance->certificate_date_issued;
            if ($targetHealth->isDirty()) {
                $targetHealth->save();
            }
            return;
        }

        $createdPrincipalHealth = HealthInsurance::create($healthData);
        $createdHealthInsuranceIds[] = (int) $createdPrincipalHealth->id;
    }

    private function syncPrincipalAttachmentsFromSource(
        Enrollee $sourcePrincipal,
        Enrollee $targetPrincipal,
        array &$createdAttachmentIds
    ): void {
        $sourceAttachments = Attachment::query()
            ->where('principal_id', $sourcePrincipal->id)
            ->whereNull('dependent_id')
            ->get();

        $targetAttachments = Attachment::query()
            ->where('principal_id', $targetPrincipal->id)
            ->whereNull('dependent_id')
            ->get();

        $existingKeys = [];
        foreach ($targetAttachments as $attachment) {
            $existingKeys[$this->attachmentIdentityKey($attachment)] = true;
        }

        foreach ($sourceAttachments as $attachment) {
            $key = $this->attachmentIdentityKey($attachment);
            if (isset($existingKeys[$key])) {
                continue;
            }

            $attachmentData = Arr::only($attachment->toArray(), (new Attachment())->getFillable());
            $attachmentData['principal_id'] = $targetPrincipal->id;
            $attachmentData['dependent_id'] = null;
            $attachmentData['notification_id'] = null;

            $createdAttachment = Attachment::create($attachmentData);
            $createdAttachmentIds[] = (int) $createdAttachment->id;
            $existingKeys[$key] = true;
        }
    }

    private function syncDependentAttachmentsFromSource(
        Dependent $sourceDependent,
        Enrollee $targetPrincipal,
        Dependent $targetDependent,
        array &$createdAttachmentIds
    ): void {
        $sourceAttachments = Attachment::query()
            ->where('dependent_id', $sourceDependent->id)
            ->get();

        $targetAttachments = Attachment::query()
            ->where('dependent_id', $targetDependent->id)
            ->get();

        $existingKeys = [];
        foreach ($targetAttachments as $attachment) {
            $existingKeys[$this->attachmentIdentityKey($attachment)] = true;
        }

        foreach ($sourceAttachments as $attachment) {
            $key = $this->attachmentIdentityKey($attachment);
            if (isset($existingKeys[$key])) {
                continue;
            }

            $attachmentData = Arr::only($attachment->toArray(), (new Attachment())->getFillable());
            $attachmentData['principal_id'] = $targetPrincipal->id;
            $attachmentData['dependent_id'] = $targetDependent->id;
            $attachmentData['notification_id'] = null;

            $createdAttachment = Attachment::create($attachmentData);
            $createdAttachmentIds[] = (int) $createdAttachment->id;
            $existingKeys[$key] = true;
        }
    }

    private function dependentSignature(Dependent $dependent): string
    {
        $parts = [
            $dependent->last_name,
            $dependent->first_name,
            $dependent->middle_name,
            $dependent->suffix,
            $dependent->relation,
            $dependent->birth_date,
        ];

        $normalized = array_map(
            fn ($value) => mb_strtoupper(trim((string) ($value ?? ''))),
            $parts
        );

        return implode('|', $normalized);
    }

    private function attachmentIdentityKey(Attachment $attachment): string
    {
        return implode('|', [
            (string) ($attachment->attachment_for ?? ''),
            (string) ($attachment->file_path ?? ''),
            (string) ($attachment->file_name ?? ''),
            (string) ($attachment->file_type ?? ''),
        ]);
    }

    public function rollbackSync(Request $request, $id)
    {
        $validated = $request->validate([
            'sync_log_id' => 'nullable|integer|exists:cm_enrollment_sync_logs,id',
            'target_enrollment_id' => 'nullable|integer|exists:cm_enrollment,id',
            'principal_ids' => 'array',
            'principal_ids.*' => 'integer',
            'dependent_ids' => 'array',
            'dependent_ids.*' => 'integer',
            'health_insurance_ids' => 'array',
            'health_insurance_ids.*' => 'integer',
            'attachment_ids' => 'array',
            'attachment_ids.*' => 'integer',
        ]);

        $sourceEnrollment = Enrollment::findOrFail((int) $id);

        $syncLog = null;
        if (!empty($validated['sync_log_id'])) {
            $syncLog = EnrollmentSyncLog::query()
                ->where('id', (int) $validated['sync_log_id'])
                ->where('source_enrollment_id', $sourceEnrollment->id)
                ->firstOrFail();

            if (!empty($syncLog->rolled_back_at)) {
                return response()->json([
                    'message' => 'This sync log has already been rolled back.',
                ], 422);
            }

            $payload = is_array($syncLog->rollback_payload) ? $syncLog->rollback_payload : [];
            $validated['target_enrollment_id'] = (int) ($payload['target_enrollment_id'] ?? 0);
            $validated['principal_ids'] = is_array($payload['principal_ids'] ?? null) ? $payload['principal_ids'] : [];
            $validated['dependent_ids'] = is_array($payload['dependent_ids'] ?? null) ? $payload['dependent_ids'] : [];
            $validated['health_insurance_ids'] = is_array($payload['health_insurance_ids'] ?? null) ? $payload['health_insurance_ids'] : [];
            $validated['attachment_ids'] = is_array($payload['attachment_ids'] ?? null) ? $payload['attachment_ids'] : [];
        }

        if (empty($validated['target_enrollment_id'])) {
            return response()->json([
                'message' => 'A target enrollment is required for rollback.',
            ], 422);
        }

        $targetEnrollment = Enrollment::findOrFail((int) $validated['target_enrollment_id']);

        if ((int) $sourceEnrollment->id === (int) $targetEnrollment->id) {
            return response()->json([
                'message' => 'Source and target enrollment must be different.',
            ], 422);
        }

        $principalIds = collect($validated['principal_ids'] ?? [])->map(fn ($item) => (int) $item)->values();
        $dependentIds = collect($validated['dependent_ids'] ?? [])->map(fn ($item) => (int) $item)->values();
        $healthIds = collect($validated['health_insurance_ids'] ?? [])->map(fn ($item) => (int) $item)->values();
        $attachmentIds = collect($validated['attachment_ids'] ?? [])->map(fn ($item) => (int) $item)->values();

        $result = DB::transaction(function () use ($request, $syncLog, $targetEnrollment, $principalIds, $dependentIds, $healthIds, $attachmentIds) {
            if ($principalIds->isNotEmpty()) {
                $validPrincipalCount = Enrollee::query()
                    ->where('enrollment_id', $targetEnrollment->id)
                    ->whereIn('id', $principalIds->all())
                    ->count();

                if ($validPrincipalCount !== $principalIds->count()) {
                    abort(422, 'Rollback principal IDs do not all belong to target enrollment.');
                }
            }

            $deletedAttachments = $attachmentIds->isEmpty()
                ? 0
                : Attachment::query()->whereIn('id', $attachmentIds->all())->delete();

            $deletedHealthInsurance = $healthIds->isEmpty()
                ? 0
                : HealthInsurance::query()->whereIn('id', $healthIds->all())->delete();

            $deletedDependents = $dependentIds->isEmpty()
                ? 0
                : Dependent::query()->whereIn('id', $dependentIds->all())->delete();

            $deletedPrincipals = $principalIds->isEmpty()
                ? 0
                : Enrollee::query()
                    ->where('enrollment_id', $targetEnrollment->id)
                    ->whereIn('id', $principalIds->all())
                    ->delete();

            if ($syncLog) {
                $syncLog->update([
                    'status' => 'rolled_back',
                    'rolled_back_at' => now(),
                    'rolled_back_by' => optional($request->user())->id,
                ]);
            }

            return [
                'deleted_attachments' => $deletedAttachments,
                'deleted_health_insurance' => $deletedHealthInsurance,
                'deleted_dependents' => $deletedDependents,
                'deleted_principals' => $deletedPrincipals,
            ];
        });

        return response()->json([
            'message' => 'Sync rollback completed.',
            'sync_log_id' => $syncLog ? (int) $syncLog->id : null,
            'summary' => $result,
        ]);
    }

    private function composeCompareRows(Collection $sourceRows, Collection $targetRows, string $entity): array
    {
        $sourceIndex = $this->buildCompareIndex($sourceRows);
        $targetIndex = $this->buildCompareIndex($targetRows);

        $allKeys = array_values(array_unique(array_merge(array_keys($sourceIndex), array_keys($targetIndex))));
        sort($allKeys);

        $all = [];
        $found = [];
        $notFoundInSource = [];
        $notFoundInTarget = [];

        foreach ($allKeys as $key) {
            $sourceBucket = $sourceIndex[$key] ?? [];
            $targetBucket = $targetIndex[$key] ?? [];
            $bucketSize = max(count($sourceBucket), count($targetBucket));

            for ($index = 0; $index < $bucketSize; $index++) {
                $sourceItem = $sourceBucket[$index] ?? null;
                $targetItem = $targetBucket[$index] ?? null;

                $status = $sourceItem && $targetItem
                    ? 'FOUND'
                    : ($targetItem ? 'NOT_FOUND_IN_SOURCE' : 'NOT_FOUND_IN_TARGET');

                $row = [
                    'status' => $status,
                    'source' => $this->mapCompareRow($sourceItem, $entity),
                    'target' => $this->mapCompareRow($targetItem, $entity),
                ];

                $all[] = $row;

                if ($status === 'FOUND') {
                    $found[] = $row;
                }

                if ($status === 'NOT_FOUND_IN_SOURCE') {
                    $notFoundInSource[] = $row;
                }

                if ($status === 'NOT_FOUND_IN_TARGET') {
                    $notFoundInTarget[] = $row;
                }
            }
        }

        return [
            'all' => $all,
            'found' => $found,
            'not_found_in_source' => $notFoundInSource,
            'not_found_in_target' => $notFoundInTarget,
            'counts' => [
                'all' => count($all),
                'found' => count($found),
                'not_found_in_source' => count($notFoundInSource),
                'not_found_in_target' => count($notFoundInTarget),
            ],
        ];
    }

    private function buildCompareIndex(Collection $rows): array
    {
        $index = [];

        foreach ($rows as $row) {
            $employeeId = $this->normalizeCompareValue(data_get($row, 'employee_id'));
            $birthDate = $this->normalizeDateValue(data_get($row, 'birth_date'));

            $key = ($employeeId !== '' && $birthDate !== '')
                ? ($employeeId . '|' . $birthDate)
                : ('ROW:' . data_get($row, 'id'));

            if (!isset($index[$key])) {
                $index[$key] = [];
            }

            $index[$key][] = $row;
        }

        return $index;
    }

    private function mapCompareRow($row, string $entity): ?array
    {
        if (!$row) {
            return null;
        }

        if ($entity === 'dependent') {
            /** @var Dependent $row */
            $certificateNumber = data_get($row, 'healthInsurance.certificate_number');

            return [
                'id' => (int) $row->id,
                'employee_id' => $row->employee_id,
                'birth_date' => $this->formatDateValue($row->birth_date),
                'fullname' => $this->buildFullName($row->first_name, $row->middle_name, $row->last_name, $row->suffix),
                'relation' => $row->relation,
                'certificate_number' => $certificateNumber,
                'enrollment_status' => $row->enrollment_status ?: $row->status,
            ];
        }

        /** @var Enrollee $row */
        $certificateNumber = data_get($row, 'healthInsurance.certificate_number');

        return [
            'id' => (int) $row->id,
            'employee_id' => $row->employee_id,
            'birth_date' => $this->formatDateValue($row->birth_date),
            'fullname' => $this->buildFullName($row->first_name, $row->middle_name, $row->last_name, $row->suffix),
            'relation' => 'PRINCIPAL',
            'certificate_number' => $certificateNumber,
            'enrollment_status' => $row->enrollment_status ?: $row->status,
        ];
    }

    private function buildFullName($firstName, $middleName, $lastName, $suffix): string
    {
        $nameParts = [
            trim((string) $firstName),
            trim((string) $middleName),
            trim((string) $lastName),
        ];

        $fullName = trim(implode(' ', array_filter($nameParts, fn ($part) => $part !== '')));
        $suffixValue = trim((string) $suffix);

        return $suffixValue !== '' ? trim($fullName . ' ' . $suffixValue) : $fullName;
    }

    private function normalizeCompareValue($value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    private function normalizeDateValue($value): string
    {
        return mb_strtoupper(trim((string) $this->formatDateValue($value)));
    }

    private function formatDateValue($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        return substr($stringValue, 0, 10);
    }

    private function columnsForCompare(): array
    {
        return [
            'first_name',
            'last_name',
            'middle_name',
            'suffix',
            'birth_date',
            'gender',
            'marital_status',
            'email1',
            'phone1',
            'department',
            'position',
            'enrollment_status',
            'status',
        ];
    }
}
