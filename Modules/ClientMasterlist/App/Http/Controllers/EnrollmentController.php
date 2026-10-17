<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Enrollment;
use App\Http\Traits\PasswordDeleteValidation;
use App\Http\Traits\LogsActions;
use Illuminate\Support\Facades\Schema;
use Modules\ClientMasterlist\App\Http\Middleware\EnsureEnrollmentAccess;
use Modules\ClientMasterlist\App\Http\Requests\Enrollments\AssignEnrollmentUserRequest;
use Modules\ClientMasterlist\App\Http\Requests\Enrollments\StoreEnrollmentRequest;
use Modules\ClientMasterlist\App\Http\Requests\Enrollments\UpdateEnrollmentRequest;
use Modules\ClientMasterlist\App\Models\UserCM;
use Modules\ClientMasterlist\App\Models\EnrollmentRole;
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
        $enrollment = Enrollment::findOrFail($id);
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
}
