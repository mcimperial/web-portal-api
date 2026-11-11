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
 * (`enrollment:read`, `principal:read`, `dependent:read`)
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
                'message' => 'This API key is not allowed to access the requested enrollment.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $enrollment,
        ]);
    }

    public function showEnrollmentPrincipals(Request $request, string $enrollmentTitle): JsonResponse
    {
        return $this->principals($request, $enrollmentTitle);
    }

    /**
     * List Principals
     *
     * Returns a paginated list of enrolled principals (employees)
     * belonging to the given enrollment. Optionally filter by
     * `enrollment_status` or `employee_id`.
     *
     * @authenticated
    *
    * Required API key permission: `principal:read`
     *
     * @urlParam id             integer required The enrollment ID. Example: 1
     * @queryParam enrollment_status string  Filter by the principal's enrollment status. Example: active
     * @queryParam employee_id        string  Filter by employee ID. Example: EMP-0042
     * @queryParam per_page           integer Number of results per page (max 100, default 20). Example: 20
     *
     * @response 200 {
     *   "success": true,
     *   "data": [{ "id": 10, "enrollment_id": 1, "employee_id": "EMP-0042", "...": "..." }],
     *   "meta": {
     *     "current_page": 1,
     *     "last_page": 2,
     *     "per_page": 20,
     *     "total": 35
     *   }
     * }
     */
    public function principals(Request $request, string $enrollmentTitle): JsonResponse
    {
        $enrollment = $this->resolveEnrollmentByTitle($enrollmentTitle);

        if (!$this->isEnrollmentAllowed($request, $enrollment->title)) {
            return response()->json([
                'success' => false,
                'message' => 'This API key is not allowed to access the requested enrollment.',
            ], 403);
        }

        $query = Enrollee::where('enrollment_id', $enrollment->id)
            ->when($request->filled('enrollment_status'), fn ($q) => $q->where('enrollment_status', $request->enrollment_status))
            ->when($request->filled('employee_id'),       fn ($q) => $q->where('employee_id', $request->employee_id));

        $perPage = min((int) $request->get('per_page', 20), 100);
        $result  = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'enrollment' => [
                'title' => $enrollment->title,
            ],
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
                'message' => 'This API key is not allowed to access the requested enrollment.',
            ], 403);
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
                'message' => 'This API key is not allowed to access the requested enrollment.',
            ], 403);
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
