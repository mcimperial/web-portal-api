<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Enrollment;
use App\Http\Traits\UppercaseInput;
use App\Http\Traits\PasswordDeleteValidation;
use Illuminate\Support\Facades\Schema;

class EnrollmentController extends Controller
{
    use UppercaseInput, PasswordDeleteValidation;
    public function index()
    {
        return Enrollment::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id' => 'nullable|integer|exists:company,id',
            'insurance_provider_id' => 'nullable|integer|exists:cm_insurance_provider,id',
            'title' => 'nullable|string',
            'note' => 'nullable|string',
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
        return response()->json($enrollment, 201);
    }

    public function show($id)
    {
        return Enrollment::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $enrollment = Enrollment::findOrFail($id);
        $validated = $request->validate([
            'company_id' => 'nullable|integer|exists:company,id',
            'insurance_provider_id' => 'nullable|integer|exists:cm_insurance_provider,id',
            'title' => 'nullable|string',
            'note' => 'nullable|string',
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
        $enrollment->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
}
