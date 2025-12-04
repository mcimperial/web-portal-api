<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Enrollment;
use App\Http\Traits\UppercaseInput;
use App\Http\Traits\PasswordDeleteValidation;
use App\Http\Traits\LogsActions;
use Illuminate\Support\Facades\Schema;

use Modules\ClientMasterlist\App\Models\UserCM;
use Modules\ClientMasterlist\App\Models\EnrollmentRole;

class EnrollmentController extends Controller
{
    use UppercaseInput, PasswordDeleteValidation, LogsActions;

    public function index()
    {
        $ids = request()->query('ids');
        $isadmin = request()->query('isadmin');

        if (!$isadmin) {
            $idArray = array_filter(array_map('intval', explode(',', $ids)));
            return Enrollment::whereIn('id', $idArray)->get();
        } else {
            return Enrollment::all();
        }

        return Enrollment::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id' => 'nullable|integer|exists:company,id',
            'insurance_provider_id' => 'nullable|integer|exists:cm_insurance_provider,id',
            'title' => 'nullable|string',
            'note' => 'nullable|string',
            'premium' => 'nullable|numeric',
            'premium_computation' => 'nullable|string',
            'premium_variable' => 'nullable|string',
            'with_monthly' => 'nullable|boolean',
            'principal_mbl' => 'nullable|numeric',
            'principal_room_and_board' => 'nullable|string',
            'dependent_mbl' => 'nullable|numeric',
            'dependent_room_and_board' => 'nullable|string',
            'with_address' => 'nullable|boolean',
            'with_skip_hierarchy' => 'nullable|boolean',
            'status' => 'nullable|string',
        ]);
        // Only uppercase string fields, keep ids as is
        $validated = $this->uppercaseStrings($validated);
        if (isset($validated['company_id'])) {
            $validated['company_id'] = (int) $request->input('company_id');
        }
        if (isset($validated['insurance_provider_id'])) {
            $validated['insurance_provider_id'] = (int) $request->input('insurance_provider_id');
        }
        $enrollment = Enrollment::create($validated);
        
        // Log the create action
        $this->logCreate($enrollment, ['request_data' => $request->all()]);
        
        return response()->json($enrollment, 201);
    }

    public function show($id)
    {
        return Enrollment::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $enrollment = Enrollment::findOrFail($id);
        $oldValues = $enrollment->toArray();
        $validated = $request->validate([
            'company_id' => 'nullable|integer|exists:company,id',
            'insurance_provider_id' => 'nullable|integer|exists:cm_insurance_provider,id',
            'title' => 'nullable|string',
            'note' => 'nullable|string',
            'premium' => 'nullable|numeric',
            'premium_computation' => 'nullable|string',
            'premium_variable' => 'nullable|string',
            'with_monthly' => 'nullable|boolean',
            'principal_mbl' => 'nullable|numeric',
            'principal_room_and_board' => 'nullable|string',
            'dependent_mbl' => 'nullable|numeric',
            'dependent_room_and_board' => 'nullable|string',
            'with_address' => 'nullable|boolean',
            'with_skip_hierarchy' => 'nullable|boolean',
            'status' => 'nullable|string',
        ]);
        // Only uppercase string fields, keep ids as is
        $validated = $this->uppercaseStrings($validated);
        if (isset($validated['company_id'])) {
            $validated['company_id'] = (int) $request->input('company_id');
        }
        if (isset($validated['insurance_provider_id'])) {
            $validated['insurance_provider_id'] = (int) $request->input('insurance_provider_id');
        }
        $enrollment->update($validated);
        
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

    public function assignUserToEnrollment(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'enrollment_id' => 'required|integer|exists:cm_enrollment,id',
        ]);

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
