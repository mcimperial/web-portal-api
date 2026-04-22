<?php

namespace Modules\ClientThirdParty\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Models\Enrollment;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Dependent;

class EnrollmentApiController extends Controller
{
    // -----------------------------------------------------------------------
    // GET /api/v1/third-party/enrollments
    // List enrollments (optionally filter by status, company_id)
    // Required permission: enrollment:read
    // -----------------------------------------------------------------------
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

    // -----------------------------------------------------------------------
    // GET /api/v1/third-party/enrollments/{id}
    // Show a single enrollment
    // -----------------------------------------------------------------------
    public function showEnrollment(int $id): JsonResponse
    {
        $enrollment = Enrollment::with(['insuranceProvider', 'company'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $enrollment,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /api/v1/third-party/enrollments/{id}/principals
    // List enrolled principals (employees) for an enrollment
    // -----------------------------------------------------------------------
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

    // -----------------------------------------------------------------------
    // GET /api/v1/third-party/enrollments/{id}/principals/{principalId}/dependents
    // List dependents for a principal
    // -----------------------------------------------------------------------
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

    // -----------------------------------------------------------------------
    // GET /api/v1/third-party/enrollments/{id}/summary
    // Quick stats summary for an enrollment
    // -----------------------------------------------------------------------
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

    // -----------------------------------------------------------------------
    // GET /api/v1/third-party/principals/search
    // Search principals across all enrollments by employee_id, name, or birth_date.
    //
    // Query params (at least one required):
    //   employee_id  – exact match
    //   name         – searches first_name, last_name, middle_name (LIKE)
    //   birth_date   – exact date match (YYYY-MM-DD)
    //   enrollment_id – optional scope to a single enrollment
    //   per_page     – default 20, max 100
    // -----------------------------------------------------------------------
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
