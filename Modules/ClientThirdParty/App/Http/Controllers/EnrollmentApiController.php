<?php

namespace Modules\ClientThirdParty\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Traits\UppercaseInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Modules\ClientMasterlist\App\Models\Enrollment;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Dependent;
use Modules\ClientMasterlist\App\Models\HealthInsurance;
use Modules\ClientThirdParty\App\Models\ApiCredential;

/**
 * @group Third-Party Enrollment API
 *
 * Endpoints for third-party integrations to read enrollment data,
 * principals (employees), dependents, and summaries.
 *
 * All routes are prefixed with `/api/v1/third-party` and require
 * `X-API-Key` authentication with route-level permissions
 * (`enrollment:read`, `principal:list:read`, `principal:read`, `dependent:read`)
 * unless otherwise noted.
 */
class EnrollmentApiController extends Controller
{
    use UppercaseInput;

    /**
     * List Enrollments
     *
     * Returns a paginated list of enrollments. Optionally filter by
     * `company_id` and/or `status`.
     *
     * @authenticated
    *
    * Required API key permission: `enrollment:read`
     *
     * @queryParam company_id  integer  Filter by company ID. Example: 5
     * @queryParam status      string   Filter by enrollment status (e.g. `active`, `inactive`). Example: active
        * @queryParam enrollment_title string Filter by enrollment title (single or comma-separated). Example: Acme 2026 Enrollment
     * @queryParam per_page    integer  Number of results per page (max 100, default 15). Example: 15
     *
     * @response 200 {
     *   "success": true,
     *   "data": [{ "id": 1, "company_id": 5, "status": "active", "..." : "..." }],
     *   "meta": {
     *     "current_page": 1,
     *     "last_page": 3,
     *     "per_page": 15,
     *     "total": 42
     *   }
     * }
     */
    public function indexEnrollments(Request $request): JsonResponse
    {
        $allowedEnrollmentTitles = $this->getAllowedEnrollmentTitles($request);

        $query = Enrollment::with(['insuranceProvider', 'company'])
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->company_id))
            ->when($request->filled('status'),     fn ($q) => $q->where('status', $request->status));

        if (!empty($allowedEnrollmentTitles)) {
            $this->applyEnrollmentTitleFilter($query, $allowedEnrollmentTitles);
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $result  = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $result->items(),
            'meta'    => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ]);
    }

    /**
     * Show Enrollment
     *
     * Returns the details of a single enrollment, including its
     * insurance provider and company relationships.
     *
     * @authenticated
    *
    * Required API key permission: `enrollment:read`
     *
     * @urlParam id integer required The enrollment ID. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": { "id": 1, "company_id": 5, "status": "active", "...": "..." }
     * }
     * @response 404 { "message": "No query results for model [Enrollment] 999" }
     */
    public function showEnrollment(Request $request): JsonResponse
    {
        $provider = trim((string) $request->query('provider', ''));

        [$enrollment, $providerError] = $this->resolveEnrollmentByProvider($request, $provider);

        if (!$enrollment) {
            return response()->json([
                'success' => false,
                'message' => $providerError ?? 'Provider is not allowed for this API key.',
                'errors' => [
                    [
                        'provider' => $provider ?: null,
                        'message' => $providerError ?? 'Provider is not allowed for this API key.',
                        'allowed_providers' => $this->getAllowedProviderTitles($request),
                    ],
                ],
            ], 422);
        }

        $enrollment->load(['insuranceProvider', 'company']);

        return response()->json([
            'success' => true,
            'data'    => $enrollment,
        ]);
    }

    /**
     * List Principals (Categorized)
     *
     * Returns a paginated list of principals grouped by enrollment title.
     * The result is always scoped by the API key's `params.enrollment_titles`
     * when configured.
     *
     * @authenticated
    *
    * Required API key permission: `principal:list:read`
     *
     * @queryParam enrollment_title string Optional enrollment title filter (must be within allowed titles). Example: Acme 2026 Enrollment
     * @queryParam enrollment_status string  Filter by the principal's enrollment status. Example: active
     * @queryParam employee_id        string  Filter by employee ID. Example: EMP-0042
     * @queryParam search             string  Search by employee_id / first_name / last_name / middle_name. Example: EMP-0042
     * @queryParam per_page           integer Number of results per page (max 100, default 20). Example: 20
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "enrollment_title": "Acme 2026 Enrollment",
     *       "total": 1,
     *       "principals": [{ "id": 10, "employee_id": "EMP-0042", "...": "..." }]
     *     }
     *   ],
     *   "meta": {
     *     "current_page": 1,
     *     "last_page": 2,
     *     "per_page": 20,
     *     "total": 35
     *   }
     * }
     */
    public function listPrincipals(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_title' => 'nullable|string|max:255',
            'enrollment_status' => 'nullable|string|max:255',
            'employee_id' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $requestedEnrollmentTitle = isset($validated['enrollment_title'])
            ? trim((string) $validated['enrollment_title'])
            : null;

        if ($requestedEnrollmentTitle !== null && $requestedEnrollmentTitle !== '' && !$this->isEnrollmentAllowed($request, $requestedEnrollmentTitle)) {
            return response()->json([
                'success' => false,
                'message' => 'Enrollment not found within the selected enrollments.',
            ], 404);
        }

        $allowedEnrollmentTitles = $this->getAllowedEnrollmentTitles($request);

        $query = Enrollee::query()
            ->with(['enrollment:id,title', 'dependents'])
            ->when(!empty($allowedEnrollmentTitles), function ($q) use ($allowedEnrollmentTitles) {
                $q->whereHas('enrollment', function ($enrollmentQuery) use ($allowedEnrollmentTitles) {
                    $this->applyEnrollmentTitleFilter($enrollmentQuery, $allowedEnrollmentTitles);
                });
            })
            ->when(!empty($requestedEnrollmentTitle), function ($q) use ($requestedEnrollmentTitle) {
                $q->whereHas('enrollment', function ($enrollmentQuery) use ($requestedEnrollmentTitle) {
                    $enrollmentQuery->whereRaw('LOWER(title) = ?', [mb_strtolower($requestedEnrollmentTitle)]);
                });
            })
            ->when(!empty($validated['enrollment_status']), fn ($q) => $q->where('enrollment_status', $validated['enrollment_status']))
            ->when(!empty($validated['employee_id']), fn ($q) => $q->where('employee_id', $validated['employee_id']))
            ->when(!empty($validated['search']), function ($q) use ($validated) {
                $searchTerm = '%' . trim((string) $validated['search']) . '%';

                $q->where(function ($searchQuery) use ($searchTerm) {
                    $searchQuery
                        ->where('employee_id', 'like', $searchTerm)
                        ->orWhere('first_name', 'like', $searchTerm)
                        ->orWhere('last_name', 'like', $searchTerm)
                        ->orWhere('middle_name', 'like', $searchTerm)
                        ->orWhereHas('enrollment', function ($enrollmentQuery) use ($searchTerm) {
                            $enrollmentQuery->where('title', 'like', $searchTerm);
                        });
                });
            })
            ->orderBy('enrollment_id')
            ->orderBy('last_name')
            ->orderBy('first_name');

        $perPage = (int) ($validated['per_page'] ?? 20);
        $result  = $query->paginate($perPage);

        $grouped = collect($result->items())
            ->groupBy(fn (Enrollee $principal) => $principal->enrollment?->title ?? 'Unknown Enrollment')
            ->map(function ($items, $title) {
                return [
                    'enrollment_title' => $title,
                    'total' => $items->count(),
                    'principals' => $items->map(fn (Enrollee $principal) => $principal->toArray())->values()->all(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'filters' => [
                'enrollment_title' => $requestedEnrollmentTitle,
                'enrollment_status' => $validated['enrollment_status'] ?? null,
                'employee_id' => $validated['employee_id'] ?? null,
                'search' => $validated['search'] ?? null,
            ],
            'data'    => $grouped,
            'meta'    => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ]);
    }

    /**
     * Show Single Principal (Read)
     *
     * Returns one principal record by params, scoped to selected enrollments.
     *
     * @authenticated
    *
    * Required API key permission: `principal:read`
     *
     * @queryParam employee_id string required Principal employee ID. Example: EMP-0042
     *
     * @response 200 {
     *   "success": true,
     *   "data": { "id": 10, "employee_id": "EMP-0042", "...": "..." }
     * }
     */
    public function showPrincipal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|string|max:255',
        ]);

        $allowedEnrollmentTitles = $this->getAllowedEnrollmentTitles($request);

        $principal = Enrollee::query()
            ->with(['enrollment:id,title', 'dependents'])
            ->where('employee_id', $validated['employee_id'])
            ->when(!empty($allowedEnrollmentTitles), function ($q) use ($allowedEnrollmentTitles) {
                $q->whereHas('enrollment', function ($enrollmentQuery) use ($allowedEnrollmentTitles) {
                    $this->applyEnrollmentTitleFilter($enrollmentQuery, $allowedEnrollmentTitles);
                });
            })
            ->orderByDesc('id')
            ->first();

        if (!$principal) {
            return response()->json([
                'success' => false,
                'message' => 'Principal not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'enrollment' => [
                'title' => $principal->enrollment?->title,
            ],
            'data' => $principal,
        ]);
    }

    public function createPrincipal(Request $request): JsonResponse
    {
        [$items, $isBatch] = $this->extractDataItems($request);

        $created = [];
        $errors = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $errors[] = [
                    'index' => $index,
                    'provider' => null,
                    'message' => 'Each batch item must be a JSON object.',
                ];
                continue;
            }

            $invalidPrincipalFieldError = $this->validatePrincipalAllowedFields($item, false);
            if ($invalidPrincipalFieldError !== null) {
                $errors[] = [
                    'index' => $index,
                    'provider' => $item['provider'] ?? null,
                    'message' => $invalidPrincipalFieldError,
                ];
                continue;
            }

            $item = $this->normalizePrincipalPayloadAliases($item);

            $provider = trim((string) ($item['provider'] ?? ''));

            if ($provider === '') {
                $errors[] = $this->buildProviderError(
                    $request,
                    $index,
                    null,
                    'Provider is required.'
                );
                continue;
            }

            [$enrollment, $providerError] = $this->resolveEnrollmentByProvider($request, $provider);

            if (!$enrollment) {
                $errors[] = $this->buildProviderError($request, $index, $provider, $providerError);
                continue;
            }

            $principalData = $this->validatePrincipalData($item, false);
            $insuranceData = [];

            if ($this->requiresResignedPermission($principalData) && !$this->hasCredentialPermission($request, 'principal:resigned:update')) {
                $errors[] = [
                    'index' => $index,
                    'provider' => $provider,
                    'message' => 'Permission `principal:resigned:update` is required when employee_end_date is provided.',
                ];
                continue;
            }

            $employeeId = trim((string) ($principalData['employee_id'] ?? ''));

            if ($employeeId !== '') {
                $duplicateExists = Enrollee::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->whereRaw('LOWER(employee_id) = ?', [mb_strtolower($employeeId)])
                    ->exists();

                if ($duplicateExists) {
                    $errors[] = [
                        'index' => $index,
                        'provider' => $provider,
                        'message' => 'Duplicate employee_id. A principal with this employee_id already exists in the resolved enrollment.',
                    ];
                    continue;
                }
            }

            $this->applyPrincipalBusinessRules($principalData, $insuranceData);

            $principal = Enrollee::create(array_merge($this->uppercaseStrings($principalData), [
                'enrollment_id' => $enrollment->id,
                'company_id' => $enrollment->company_id,
            ]));

            $created[] = [
                'provider' => $provider,
                'enrollment' => [
                    'title' => $enrollment->title,
                ],
                'data' => $principal->fresh()->load(['enrollment:id,title', 'dependents', 'healthInsurance']),
            ];
        }

        return $this->buildWriteResponse(
            resourceType: 'Principal',
            action: 'created',
            isBatch: $isBatch,
            successes: $created,
            errors: $errors,
            successStatusCode: 201
        );
    }

    public function updatePrincipal(Request $request, ?string $principalEmployeeId = null): JsonResponse
    {
        [$items, $isBatch] = $this->extractDataItems($request);

        $updated = [];
        $errors = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $errors[] = [
                    'index' => $index,
                    'provider' => null,
                    'message' => 'Each batch item must be a JSON object.',
                ];
                continue;
            }

            $invalidPrincipalFieldError = $this->validatePrincipalAllowedFields($item, true);
            if ($invalidPrincipalFieldError !== null) {
                $errors[] = [
                    'index' => $index,
                    'provider' => $item['provider'] ?? null,
                    'message' => $invalidPrincipalFieldError,
                ];
                continue;
            }

            $item = $this->normalizePrincipalPayloadAliases($item);

            $provider = trim((string) ($item['provider'] ?? ''));

            $resolvedPrincipalEmployeeId = trim((string) (
                $item['principal_employee_id']
                ?? $principalEmployeeId
                ?? $item['employee_id']
                ?? ''
            ));

            if ($resolvedPrincipalEmployeeId === '') {
                $errors[] = [
                    'index' => $index,
                    'provider' => $provider,
                    'message' => 'Principal employee_id is required.',
                ];
                continue;
            }

            $principalsToUpdate = collect();

            if ($provider !== '') {
                [$enrollment, $providerError] = $this->resolveEnrollmentByProvider($request, $provider);

                if (!$enrollment) {
                    $errors[] = $this->buildProviderError($request, $index, $provider, $providerError);
                    continue;
                }

                $principal = Enrollee::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('employee_id', $resolvedPrincipalEmployeeId)
                    ->first();

                if (!$principal) {
                    $errors[] = [
                        'index' => $index,
                        'provider' => $provider,
                        'message' => 'Principal employee_id not found for the resolved provider enrollment.',
                    ];
                    continue;
                }

                $principalsToUpdate = collect([$principal]);
            } else {
                $principalsToUpdate = $this->resolvePrincipalsByEmployeeId($request, $resolvedPrincipalEmployeeId);

                if ($principalsToUpdate->isEmpty()) {
                    $errors[] = [
                        'index' => $index,
                        'provider' => null,
                        'message' => 'Principal employee_id not found within API key accessible enrollments.',
                    ];
                    continue;
                }
            }

            $principalData = $this->validatePrincipalData($item, true);
            $insuranceData = [];

            if ($this->requiresResignedPermission($principalData) && !$this->hasCredentialPermission($request, 'principal:resigned:update')) {
                $errors[] = [
                    'index' => $index,
                    'provider' => $provider,
                    'message' => 'Permission `principal:resigned:update` is required when employee_end_date is provided.',
                ];
                continue;
            }

            foreach ($principalsToUpdate as $principal) {
                $currentPrincipalData = $principalData;
                $currentInsuranceData = $insuranceData;

                $this->applyPrincipalBusinessRules($currentPrincipalData, $currentInsuranceData);

                if (!empty($currentPrincipalData)) {
                    $principal->update($this->uppercaseStrings($currentPrincipalData));
                }

                $principal->load(['enrollment:id,title,insurance_provider_id', 'enrollment.insuranceProvider:id,title']);

                $resolvedProvider = $provider !== ''
                    ? $provider
                    : trim((string) ($principal->enrollment?->insuranceProvider?->title ?? ''));

                $updated[] = [
                    'provider' => $resolvedProvider !== '' ? $resolvedProvider : null,
                    'enrollment' => [
                        'title' => $principal->enrollment?->title,
                    ],
                    'data' => $principal->fresh()->load(['enrollment:id,title', 'dependents', 'healthInsurance']),
                ];
            }
        }

        return $this->buildWriteResponse(
            resourceType: 'Principal',
            action: 'updated',
            isBatch: $isBatch,
            successes: $updated,
            errors: $errors,
            successStatusCode: 200
        );
    }

    /**
     * List Dependents
     *
     * Returns all dependents registered under a specific principal
     * within the given enrollment.
     *
     * @authenticated
    *
    * Required API key permission: `dependent:read`
     *
     * @urlParam id          integer required The enrollment ID. Example: 1
     * @urlParam principalId integer required The principal (enrollee) ID. Example: 10
     *
     * @response 200 {
     *   "success": true,
     *   "data": [{ "id": 20, "principal_id": 10, "first_name": "Jane", "...": "..." }],
     *   "total": 2
     * }
     */
    public function dependents(Request $request, string $principalEmployeeId): JsonResponse
    {
        $provider = trim((string) $request->query('provider', ''));

        [$enrollment, $providerError] = $this->resolveEnrollmentByProvider($request, $provider);

        if (!$enrollment) {
            return response()->json([
                'success' => false,
                'message' => $providerError ?? 'Provider is not allowed for this API key.',
                'errors' => [
                    [
                        'provider' => $provider ?: null,
                        'message' => $providerError ?? 'Provider is not allowed for this API key.',
                        'allowed_providers' => $this->getAllowedProviderTitles($request),
                    ],
                ],
            ], 422);
        }

        $principal = Enrollee::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('employee_id', $principalEmployeeId)
            ->first();

        if (!$principal) {
            return response()->json([
                'success' => false,
                'message' => 'Principal employee_id not found for the requested enrollment.',
            ], 404);
        }

        $dependents = Dependent::where('principal_id', $principal->id)->get();

        return response()->json([
            'success' => true,
            'enrollment' => [
                'title' => $enrollment->title,
            ],
            'data'    => $dependents,
            'total'   => $dependents->count(),
        ]);
    }

    public function createDependent(Request $request, ?string $principalEmployeeId = null): JsonResponse
    {
        [$items, $isBatch] = $this->extractDataItems($request);

        $created = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $provider = trim((string) ($item['provider'] ?? ''));

            if ($provider === '') {
                $errors[] = $this->buildProviderError(
                    $request,
                    $index,
                    null,
                    'Provider is required.'
                );
                continue;
            }

            [$enrollment, $providerError] = $this->resolveEnrollmentByProvider($request, $provider);

            if (!$enrollment) {
                $errors[] = $this->buildProviderError($request, $index, $provider, $providerError);
                continue;
            }

            $resolvedPrincipalEmployeeId = trim((string) (
                $item['principal_employee_id']
                ?? $principalEmployeeId
                ?? ''
            ));

            if ($resolvedPrincipalEmployeeId === '') {
                $errors[] = [
                    'index' => $index,
                    'provider' => $provider,
                    'message' => 'Principal employee_id is required.',
                ];
                continue;
            }

            $principal = Enrollee::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('employee_id', $resolvedPrincipalEmployeeId)
                ->first();

            if (!$principal) {
                $errors[] = [
                    'index' => $index,
                    'provider' => $provider,
                    'message' => 'Principal employee_id not found for the resolved provider enrollment.',
                ];
                continue;
            }

            $dependentData = $this->validateDependentDataForApi($item, false);
            $insuranceData = $this->validateDependentInsuranceData($item, false);

            $this->applyDependentBusinessRules($dependentData, $insuranceData);

            $dependent = Dependent::create(array_merge($this->uppercaseStrings($dependentData), [
                'principal_id' => $principal->id,
                'employee_id' => $dependentData['employee_id'] ?? $principal->employee_id,
            ]));

            $this->upsertDependentInsurance($dependent, $insuranceData, $principal->id);
            $principal->touch();

            $created[] = [
                'provider' => $provider,
                'enrollment' => [
                    'title' => $enrollment->title,
                ],
                'principal' => [
                    'employee_id' => $principal->employee_id,
                ],
                'data' => $dependent->fresh()->load('healthInsurance'),
            ];
        }

        return $this->buildWriteResponse(
            resourceType: 'Dependent',
            action: 'created',
            isBatch: $isBatch,
            successes: $created,
            errors: $errors,
            successStatusCode: 201
        );
    }

    public function updateDependent(Request $request, ?string $principalEmployeeId = null, ?int $dependentId = null): JsonResponse
    {
        [$items, $isBatch] = $this->extractDataItems($request);

        $updated = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $provider = trim((string) ($item['provider'] ?? ''));

            if ($provider === '') {
                $errors[] = $this->buildProviderError(
                    $request,
                    $index,
                    null,
                    'Provider is required.'
                );
                continue;
            }

            [$enrollment, $providerError] = $this->resolveEnrollmentByProvider($request, $provider);

            if (!$enrollment) {
                $errors[] = $this->buildProviderError($request, $index, $provider, $providerError);
                continue;
            }

            $resolvedPrincipalEmployeeId = trim((string) (
                $item['principal_employee_id']
                ?? $principalEmployeeId
                ?? ''
            ));

            if ($resolvedPrincipalEmployeeId === '') {
                $errors[] = [
                    'index' => $index,
                    'provider' => $provider,
                    'message' => 'Principal employee_id is required.',
                ];
                continue;
            }

            $resolvedDependentId = (int) ($item['dependent_id'] ?? $dependentId ?? 0);

            if ($resolvedDependentId <= 0) {
                $errors[] = [
                    'index' => $index,
                    'provider' => $provider,
                    'message' => 'Dependent id is required.',
                ];
                continue;
            }

            $principal = Enrollee::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('employee_id', $resolvedPrincipalEmployeeId)
                ->first();

            if (!$principal) {
                $errors[] = [
                    'index' => $index,
                    'provider' => $provider,
                    'message' => 'Principal employee_id not found for the resolved provider enrollment.',
                ];
                continue;
            }

            $dependent = Dependent::query()
                ->where('id', $resolvedDependentId)
                ->where('principal_id', $principal->id)
                ->first();

            if (!$dependent) {
                $errors[] = [
                    'index' => $index,
                    'provider' => $provider,
                    'message' => 'Dependent not found for the requested principal.',
                ];
                continue;
            }

            $dependentData = $this->validateDependentDataForApi($item, true);
            $insuranceData = $this->validateDependentInsuranceData($item, true);

            $this->applyDependentBusinessRules($dependentData, $insuranceData);

            if (!empty($dependentData)) {
                $dependent->update($this->uppercaseStrings($dependentData));
            }

            $this->upsertDependentInsurance($dependent, $insuranceData, $principal->id);
            $principal->touch();

            $updated[] = [
                'provider' => $provider,
                'enrollment' => [
                    'title' => $enrollment->title,
                ],
                'principal' => [
                    'employee_id' => $principal->employee_id,
                ],
                'data' => $dependent->fresh()->load('healthInsurance'),
            ];
        }

        return $this->buildWriteResponse(
            resourceType: 'Dependent',
            action: 'updated',
            isBatch: $isBatch,
            successes: $updated,
            errors: $errors,
            successStatusCode: 200
        );
    }

    /**
     * Enrollment Summary
     *
     * Returns quick statistics for an enrollment: total principals,
     * total dependents, and a breakdown of principals grouped by
     * their `enrollment_status`.
     *
     * @authenticated
    *
    * Required API key permission: `enrollment:read`
     *
     * @urlParam id integer required The enrollment ID. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "enrollment": { "id": 1, "status": "active", "...": "..." },
     *     "total_principals": 35,
     *     "total_dependents": 12,
     *     "by_status": { "active": 30, "pending": 5 }
     *   }
     * }
     * @response 404 { "message": "No query results for model [Enrollment] 999" }
     */
    public function summary(Request $request): JsonResponse
    {
        $provider = trim((string) $request->query('provider', ''));

        [$enrollment, $providerError] = $this->resolveEnrollmentByProvider($request, $provider);

        if (!$enrollment) {
            return response()->json([
                'success' => false,
                'message' => $providerError ?? 'Provider is not allowed for this API key.',
                'errors' => [
                    [
                        'provider' => $provider ?: null,
                        'message' => $providerError ?? 'Provider is not allowed for this API key.',
                        'allowed_providers' => $this->getAllowedProviderTitles($request),
                    ],
                ],
            ], 422);
        }

        $principals = Enrollee::where('enrollment_id', $enrollment->id);
        $dependents = Dependent::where('enrollment_id', $enrollment->id);

        return response()->json([
            'success' => true,
            'data'    => [
                'enrollment'         => $enrollment,
                'total_principals'   => (clone $principals)->count(),
                'total_dependents'   => (clone $dependents)->count(),
                'by_status'          => (clone $principals)
                    ->selectRaw('enrollment_status, COUNT(*) as count')
                    ->groupBy('enrollment_status')
                    ->pluck('count', 'enrollment_status'),
            ],
        ]);
    }

    private function validatePrincipalData(array $payload, bool $isUpdate): array
    {
        $prefix = $isUpdate ? 'sometimes|' : '';

        return Validator::make($payload, [
            'employee_id' => $prefix . 'required|string|max:255',
            'first_name' => $prefix . 'required|string|max:255',
            'last_name' => $prefix . 'required|string|max:255',
            'suffix' => 'nullable|string|max:255',
            'birth_date' => $prefix . 'required|date_format:Y-m-d',
            'gender' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'email1' => $prefix . 'required|email|max:255',
            'email2' => 'nullable|email|max:255',
            'phone1' => 'nullable|string|max:255',
            'phone2' => 'nullable|string|max:255',
            'employment_start_date' => $prefix . 'required|date_format:Y-m-d',
            'employment_end_date' => 'nullable|date_format:Y-m-d',
            'back_date' => 'nullable|date_format:Y-m-d',
        ])->validate();
    }

    private function applyPrincipalBusinessRules(array &$principalData, array &$insuranceData): void
    {
        $hasEmploymentEndDate = !empty($principalData['employment_end_date']);
        $hasCoverageEndDate = !empty($insuranceData['coverage_end_date']);

        if ($hasEmploymentEndDate) {
            $principalData['status'] = 'INACTIVE';
            $principalData['enrollment_status'] = 'RESIGNED';
            $principalData['back_date'] = $principalData['employment_end_date'];
        }

        if ($hasCoverageEndDate) {
            if (empty($principalData['employment_end_date'])) {
                $principalData['employment_end_date'] = $insuranceData['coverage_end_date'];
            }

            $today = date('Y-m-d');
            if ($insuranceData['coverage_end_date'] < $today) {
                $principalData['status'] = 'INACTIVE';
                $principalData['enrollment_status'] = 'RESIGNED';
            }
        }
    }

    private function normalizePrincipalPayloadAliases(array $payload): array
    {
        if (!array_key_exists('employment_start_date', $payload) && array_key_exists('employee_start_date', $payload)) {
            $payload['employment_start_date'] = $payload['employee_start_date'];
        }

        if (!array_key_exists('employment_end_date', $payload) && array_key_exists('employee_end_date', $payload)) {
            $payload['employment_end_date'] = $payload['employee_end_date'];
        }

        unset($payload['employee_start_date'], $payload['employee_end_date']);

        return $payload;
    }

    private function validatePrincipalAllowedFields(array $payload, bool $isUpdate): ?string
    {
        $allowed = [
            'provider',
            'principal_employee_id',
            'employee_id',
            'first_name',
            'last_name',
            'suffix',
            'birth_date',
            'gender',
            'marital_status',
            'email1',
            'email2',
            'phone1',
            'phone2',
            'employee_start_date',
        ];

        if ($isUpdate) {
            $allowed[] = 'employee_end_date';
        }

        $unknownFields = array_values(array_diff(array_keys($payload), $allowed));
        if (!empty($unknownFields)) {
            return 'Unsupported principal field(s): ' . implode(', ', $unknownFields) . '.';
        }

        if ($isUpdate && !empty($payload['employee_end_date'])) {
            $resignedAllowed = ['provider', 'principal_employee_id', 'employee_id', 'employee_end_date'];
            $nonResignedFields = array_values(array_diff(array_keys($payload), $resignedAllowed));

            if (!empty($nonResignedFields)) {
                return 'Resigned update only accepts employee_id and employee_end_date.';
            }
        }

        return null;
    }

    private function requiresResignedPermission(array $principalData): bool
    {
        return !empty($principalData['employment_end_date']);
    }

    private function hasCredentialPermission(Request $request, string $permission): bool
    {
        $credential = $request->attributes->get('api_credential');

        if (!$credential instanceof ApiCredential) {
            return false;
        }

        return $credential->hasPermission($permission);
    }

    private function upsertPrincipalInsurance(Enrollee $principal, array $insuranceData): void
    {
        if (empty($insuranceData)) {
            return;
        }

        $insurancePayload = $this->uppercaseStrings(array_merge($insuranceData, [
            'principal_id' => $principal->id,
        ]));

        $insurance = HealthInsurance::where('principal_id', $principal->id)->first();

        if ($insurance) {
            $insurance->update($insurancePayload);
        } else {
            HealthInsurance::create($insurancePayload);
        }

        if (array_key_exists('is_company_paid', $insurancePayload)) {
            $dependents = $principal->dependents;

            foreach ($dependents as $dependent) {
                $dependentInsurance = HealthInsurance::where('dependent_id', $dependent->id)->first();
                if ($dependentInsurance) {
                    $dependentInsurance->update([
                        'is_company_paid' => $insurancePayload['is_company_paid'],
                    ]);
                }
            }
        }
    }

    private function validateDependentDataForApi(array $payload, bool $isUpdate): array
    {
        $prefix = $isUpdate ? 'sometimes|' : '';

        return Validator::make($payload, [
            'employee_id' => 'nullable|string|max:255',
            'first_name' => $prefix . 'required|string|max:255',
            'last_name' => $prefix . 'required|string|max:255',
            'relation' => $prefix . 'required|string|max:255',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|string|max:255',
            'nationality' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'member_id' => 'nullable|string|max:255',
            'enrollment_status' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
            'suffix' => 'nullable|string|max:255',
        ])->validate();
    }

    private function validateDependentInsuranceData(array $payload, bool $isUpdate): array
    {
        $prefix = $isUpdate ? 'sometimes|' : '';

        return Validator::make($payload, [
            'is_skipping' => 'nullable|boolean',
            'reason_for_skipping' => 'nullable|string|max:255',
            'certificate_number' => 'nullable|string|max:255',
            'coverage_start_date' => 'nullable|date',
            'coverage_end_date' => 'nullable|date',
            'certificate_date_issued' => 'nullable|date',
            'is_company_paid' => 'nullable',
        ])->validate();
    }

    private function applyDependentBusinessRules(array &$dependentData, array &$insuranceData): void
    {
        if (array_key_exists('is_company_paid', $insuranceData)) {
            $insuranceData['is_company_paid'] = $this->normalizeCompanyPaidValue($insuranceData['is_company_paid']);
        }

        if (!empty($insuranceData['certificate_number'])) {
            if (empty($insuranceData['coverage_start_date'])) {
                $insuranceData['coverage_start_date'] = date('Y-m-d', strtotime('first day of next month'));
            }

            if (empty($insuranceData['certificate_date_issued'])) {
                $insuranceData['certificate_date_issued'] = $insuranceData['coverage_start_date'];
            }

            $dependentData['enrollment_status'] = 'APPROVED';
        }
    }

    private function upsertDependentInsurance(Dependent $dependent, array $insuranceData, int $principalId): void
    {
        if (empty($insuranceData)) {
            return;
        }

        if (!array_key_exists('is_company_paid', $insuranceData)) {
            $principalInsurance = HealthInsurance::where('principal_id', $principalId)->first();
            if ($principalInsurance?->is_company_paid !== null) {
                $insuranceData['is_company_paid'] = $principalInsurance->is_company_paid;
            }
        }

        $insurancePayload = $this->uppercaseStrings(array_merge($insuranceData, [
            'dependent_id' => $dependent->id,
        ]));

        $existingInsurance = HealthInsurance::where('dependent_id', $dependent->id)->first();

        if ($existingInsurance) {
            $existingInsurance->update($insurancePayload);
        } else {
            HealthInsurance::create($insurancePayload);
        }

        if (array_key_exists('is_company_paid', $insurancePayload)) {
            $principalInsurance = HealthInsurance::where('principal_id', $principalId)->first();
            if ($principalInsurance) {
                $principalInsurance->update([
                    'is_company_paid' => $insurancePayload['is_company_paid'],
                ]);
            }
        }
    }

    private function normalizeCompanyPaidValue(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $normalized = strtoupper(trim((string) $value));

        return in_array($normalized, ['YES', 'TRUE', '1'], true) ? 1 : 0;
    }

    private function getAllowedEnrollmentTitles(Request $request): array
    {
        $credential = $request->attributes->get('api_credential');

        if (!$credential instanceof ApiCredential) {
            return [];
        }

        if (!is_array($credential->params ?? null)) {
            return [];
        }

        return $this->normalizeInputTitles($credential->params['enrollment_titles'] ?? []);
    }

    private function normalizeInputTitles(mixed $value): array
    {
        $items = is_array($value)
            ? $value
            : (is_string($value) ? explode(',', $value) : []);

        return collect($items)
            ->map(fn ($title) => trim((string) $title))
            ->filter()
            ->unique(fn ($title) => mb_strtolower($title))
            ->values()
            ->all();
    }

    private function applyEnrollmentTitleFilter($query, array $titles): void
    {
        $query->where(function ($innerQuery) use ($titles) {
            foreach ($titles as $title) {
                $innerQuery->orWhereRaw('LOWER(title) = ?', [mb_strtolower($title)]);
            }
        });
    }

    private function isEnrollmentAllowed(Request $request, ?string $enrollmentTitle): bool
    {
        $allowedTitles = $this->getAllowedEnrollmentTitles($request);

        if (empty($allowedTitles)) {
            return true;
        }

        if (!$enrollmentTitle) {
            return false;
        }

        return in_array(mb_strtolower(trim($enrollmentTitle)), array_map('mb_strtolower', $allowedTitles), true);
    }

    private function resolveEnrollmentByTitle(string $enrollmentTitle): Enrollment
    {
        $normalized = trim($enrollmentTitle);

        return Enrollment::query()
            ->whereRaw('LOWER(title) = ?', [mb_strtolower($normalized)])
            ->firstOrFail();
    }

    private function extractDataItems(Request $request): array
    {
        $payload = $request->all();

        if (isset($payload['data'])) {
            $rawData = $payload['data'];

            if (!is_array($rawData)) {
                return [[], true];
            }

            if ($rawData === []) {
                return [[], true];
            }

            $firstItem = reset($rawData);

            $items = is_array($firstItem)
                ? array_values($rawData)
                : [$rawData];

            if (empty($items)) {
                return [[], true];
            }

            return [$items, true];
        }

        return [[$payload], false];
    }

    private function buildWriteResponse(
        string $resourceType,
        string $action,
        bool $isBatch,
        array $successes,
        array $errors,
        int $successStatusCode
    ): JsonResponse {
        $total = count($successes) + count($errors);

        if ($isBatch && $total === 0) {
            return response()->json([
                'success' => false,
                'message' => 'The data array must contain at least one item.',
                'errors' => [
                    [
                        'message' => 'The data array must contain at least one item.',
                    ],
                ],
            ], 422);
        }

        if (!$isBatch) {
            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => $errors[0]['message'] ?? sprintf('%s %s failed.', $resourceType, $action),
                    'errors' => $errors,
                ], 422);
            }

            $entry = $successes[0] ?? null;

            return response()->json([
                'success' => true,
                'message' => sprintf('%s %s successfully.', $resourceType, $action),
                'provider' => $entry['provider'] ?? null,
                'enrollment' => $entry['enrollment'] ?? null,
                'principal' => $entry['principal'] ?? null,
                'data' => $entry['data'] ?? null,
            ], $successStatusCode);
        }

        $failed = count($errors);
        $succeeded = count($successes);

        $status = 200;
        if ($failed === 0) {
            $status = $successStatusCode;
        } elseif ($succeeded === 0) {
            $status = 422;
        } else {
            $status = 207;
        }

        return response()->json([
            'success' => $failed === 0,
            'message' => sprintf(
                '%s batch %s complete. %d succeeded, %d failed.',
                $resourceType,
                $action,
                $succeeded,
                $failed
            ),
            'summary' => [
                'total' => $total,
                'succeeded' => $succeeded,
                'failed' => $failed,
            ],
            'data' => $successes,
            'errors' => $errors,
        ], $status);
    }

    private function buildProviderError(Request $request, int $index, ?string $provider, ?string $message = null): array
    {
        return [
            'index' => $index,
            'provider' => $provider,
            'message' => $message ?? 'Invalid provider.',
            'allowed_providers' => $this->getAllowedProviderTitles($request),
        ];
    }

    private function resolveEnrollmentByProvider(Request $request, string $provider): array
    {
        $normalizedProvider = mb_strtolower(trim($provider));

        if ($normalizedProvider === '') {
            return [null, 'Provider is required.'];
        }

        $accessibleEnrollments = $this->getAccessibleEnrollments($request);

        $matched = $accessibleEnrollments->filter(function (Enrollment $enrollment) use ($normalizedProvider) {
            $providerTitle = trim((string) ($enrollment->insuranceProvider?->title ?? ''));
            return $providerTitle !== '' && mb_strtolower($providerTitle) === $normalizedProvider;
        })->values();

        if ($matched->isEmpty()) {
            return [null, 'Provider is not allowed for this API key.'];
        }

        if ($matched->count() > 1) {
            return [
                null,
                'Provider matches multiple enrollments. Use a provider mapped to only one enrollment or restrict this API key enrollment access.',
            ];
        }

        return [$matched->first(), null];
    }

    private function resolvePrincipalsByEmployeeId(Request $request, string $employeeId): Collection
    {
        $normalizedEmployeeId = trim($employeeId);

        if ($normalizedEmployeeId === '') {
            return collect();
        }

        $allowedEnrollmentTitles = $this->getAllowedEnrollmentTitles($request);

        return Enrollee::query()
            ->with(['enrollment:id,title,insurance_provider_id', 'enrollment.insuranceProvider:id,title'])
            ->where('employee_id', $normalizedEmployeeId)
            ->when(!empty($allowedEnrollmentTitles), function ($q) use ($allowedEnrollmentTitles) {
                $q->whereHas('enrollment', function ($enrollmentQuery) use ($allowedEnrollmentTitles) {
                    $this->applyEnrollmentTitleFilter($enrollmentQuery, $allowedEnrollmentTitles);
                });
            })
            ->get();
    }

    private function getAccessibleEnrollments(Request $request): Collection
    {
        $allowedEnrollmentTitles = $this->getAllowedEnrollmentTitles($request);

        $query = Enrollment::query()->with('insuranceProvider');

        if (!empty($allowedEnrollmentTitles)) {
            $this->applyEnrollmentTitleFilter($query, $allowedEnrollmentTitles);
        }

        return $query->get();
    }

    private function getAllowedProviderTitles(Request $request): array
    {
        return $this->getAccessibleEnrollments($request)
            ->map(fn (Enrollment $enrollment) => trim((string) ($enrollment->insuranceProvider?->title ?? '')))
            ->filter()
            ->unique(fn (string $title) => mb_strtolower($title))
            ->values()
            ->all();
    }
}
