<?php

namespace Modules\ClientThirdParty\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Models\Enrollment;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Dependent;
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
    public function showEnrollment(Request $request, string $enrollmentTitle): JsonResponse
    {
        $enrollment = $this->resolveEnrollmentByTitle($enrollmentTitle);
        $enrollment->load(['insuranceProvider', 'company']);

        if (!$this->isEnrollmentAllowed($request, $enrollment->title)) {
            return response()->json([
                'success' => false,
                'message' => 'Enrollment not found.',
            ], 404);
        }

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
     * @queryParam enrollment_title string Optional enrollment title filter (must be within allowed titles). Example: Acme 2026 Enrollment
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
            'enrollment_title' => 'nullable|string|max:255',
        ]);

        $requestedEnrollmentTitle = isset($validated['enrollment_title'])
            ? trim((string) $validated['enrollment_title'])
            : null;

        if ($requestedEnrollmentTitle !== null && $requestedEnrollmentTitle !== '' && !$this->isEnrollmentAllowed($request, $requestedEnrollmentTitle)) {
            return response()->json([
                'success' => false,
                'message' => 'Principal not found.',
            ], 404);
        }

        $allowedEnrollmentTitles = $this->getAllowedEnrollmentTitles($request);

        $principal = Enrollee::query()
            ->with(['enrollment:id,title', 'dependents'])
            ->where('employee_id', $validated['employee_id'])
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
    public function dependents(Request $request, string $enrollmentTitle, string $principalEmployeeId): JsonResponse
    {
        $enrollment = $this->resolveEnrollmentByTitle($enrollmentTitle);

        if (!$this->isEnrollmentAllowed($request, $enrollment->title)) {
            return response()->json([
                'success' => false,
                'message' => 'Enrollment not found.',
            ], 404);
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
    public function summary(Request $request, string $enrollmentTitle): JsonResponse
    {
        $enrollment = $this->resolveEnrollmentByTitle($enrollmentTitle);

        if (!$this->isEnrollmentAllowed($request, $enrollment->title)) {
            return response()->json([
                'success' => false,
                'message' => 'Enrollment not found.',
            ], 404);
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
}
