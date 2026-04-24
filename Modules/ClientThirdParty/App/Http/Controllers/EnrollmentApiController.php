<?php

namespace Modules\ClientThirdParty\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Models\Enrollment;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Dependent;

/**
 * @group Third-Party Enrollment API
 *
 * Endpoints for third-party integrations to read enrollment data,
 * principals (employees), dependents, and summaries.
 *
 * All routes are prefixed with `/api/v1/third-party` and require
 * Bearer token authentication with the `enrollment:read` permission
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
     * @queryParam company_id  integer  Filter by company ID. Example: 5
     * @queryParam status      string   Filter by enrollment status (e.g. `active`, `inactive`). Example: active
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
        $query = Enrollment::with(['insuranceProvider', 'company'])
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->company_id))
            ->when($request->filled('status'),     fn ($q) => $q->where('status', $request->status));

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
     * @urlParam id integer required The enrollment ID. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": { "id": 1, "company_id": 5, "status": "active", "...": "..." }
     * }
     * @response 404 { "message": "No query results for model [Enrollment] 999" }
     */
    public function showEnrollment(int $id): JsonResponse
    {
        $enrollment = Enrollment::with(['insuranceProvider', 'company'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $enrollment,
        ]);
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
    public function principals(Request $request, int $enrollmentId): JsonResponse
    {
        $query = Enrollee::where('enrollment_id', $enrollmentId)
            ->when($request->filled('enrollment_status'), fn ($q) => $q->where('enrollment_status', $request->enrollment_status))
            ->when($request->filled('employee_id'),       fn ($q) => $q->where('employee_id', $request->employee_id));

        $perPage = min((int) $request->get('per_page', 20), 100);
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
     * List Dependents
     *
     * Returns all dependents registered under a specific principal
     * within the given enrollment.
     *
     * @authenticated
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
    public function dependents(Request $request, int $enrollmentId, int $principalId): JsonResponse
    {
        $dependents = Dependent::where('enrollment_id', $enrollmentId)
            ->where('principal_id', $principalId)
            ->get();

        return response()->json([
            'success' => true,
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
    public function summary(int $id): JsonResponse
    {
        $enrollment = Enrollment::findOrFail($id);

        $principals = Enrollee::where('enrollment_id', $id);
        $dependents = Dependent::where('enrollment_id', $id);

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

    /**
     * Search Principals
     *
     * Searches principals (employees) across all enrollments.
     * At least one of `employee_id`, `name`, or `birth_date` must be provided.
     * Results include each principal's dependents.
     *
     * @authenticated
     *
     * @queryParam employee_id   string  Exact match on employee ID. Example: EMP-0042
     * @queryParam name          string  Partial match on first, last, or middle name (min 2 chars). Example: John
     * @queryParam birth_date    string  Exact date match in `YYYY-MM-DD` format. Example: 1990-05-14
     * @queryParam enrollment_id integer Narrow search to a specific enrollment. Example: 1
     * @queryParam per_page      integer Number of results per page (max 100, default 20). Example: 20
     *
     * @response 200 {
     *   "success": true,
     *   "data": [{
     *     "id": 10,
     *     "employee_id": "EMP-0042",
     *     "first_name": "John",
     *     "last_name": "Doe",
     *     "dependents": []
     *   }],
     *   "meta": {
     *     "current_page": 1,
     *     "last_page": 1,
     *     "per_page": 20,
     *     "total": 1
     *   }
     * }
     * @response 422 {
     *   "success": false,
     *   "message": "Provide at least one search parameter: employee_id, name, or birth_date."
     * }
     */
    public function searchPrincipals(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id'   => 'nullable|string',
            'name'          => 'nullable|string|min:2',
            'birth_date'    => 'nullable|date_format:Y-m-d',
            'enrollment_id' => 'nullable|integer',
        ]);

        // At least one search parameter must be provided
        if (!$request->filled('employee_id') &&
            !$request->filled('name') &&
            !$request->filled('birth_date')) {
            return response()->json([
                'success' => false,
                'message' => 'Provide at least one search parameter: employee_id, name, or birth_date.',
            ], 422);
        }

        $query = Enrollee::with('dependents')
            ->when($request->filled('enrollment_id'), fn ($q) =>
                $q->where('enrollment_id', $request->enrollment_id)
            )
            ->when($request->filled('employee_id'), fn ($q) =>
                $q->where('employee_id', $request->employee_id)
            )
            ->when($request->filled('name'), function ($q) use ($request) {
                $term = '%' . $request->name . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('first_name',  'LIKE', $term)
                          ->orWhere('last_name',   'LIKE', $term)
                          ->orWhere('middle_name', 'LIKE', $term)
                          ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$term]);
                });
            })
            ->when($request->filled('birth_date'), fn ($q) =>
                $q->whereDate('birth_date', $request->birth_date)
            );

        $perPage = min((int) $request->get('per_page', 20), 100);
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
}
