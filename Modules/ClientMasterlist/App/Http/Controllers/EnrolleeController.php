<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\HealthInsurance;
use App\Http\Traits\UppercaseInput;
use App\Http\Traits\PasswordDeleteValidation;

class EnrolleeController extends Controller
{
    use UppercaseInput, PasswordDeleteValidation;

    // List all enrollees for an enrollment
    public function index(Request $request)
    {
        $enrollmentId = $request->query('enrollment_id');
        $query = Enrollee::with(['dependents', 'healthInsurance']);
        if ($enrollmentId) {
            $query->where('enrollment_id', $enrollmentId);
        }
        $enrollees = $query->get();
        return response()->json($enrollees);
    }

    // Store a new enrollee with dependents
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'birth_date' => 'required|date',
            'gender' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'with_dependents' => 'nullable|boolean',
            'enrollment_status' => 'required|string',
            'email1' => 'required|email',
            'email2' => 'nullable|email',
            'phone1' => 'nullable|string|max:255',
            'phone2' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'employment_start_date' => 'nullable|date',
            'employment_end_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'status' => 'required|string|in:ACTIVE,INACTIVE',
            'enrollment_id' => 'required',
        ]);
        $insuranceData = $request->validate([
            'plan' => 'nullable|string|max:255',
            'premium' => 'nullable|numeric',
            'principal_mbl' => 'nullable|numeric',
            'principal_room_and_board' => 'nullable|string|max:255',
            'dependent_mbl' => 'nullable|numeric',
            'dependent_room_and_board' => 'nullable|string|max:255',
            'is_renewal' => 'nullable|boolean',
            'is_company_paid' => 'nullable|boolean',
            'coverage_start_date' => 'nullable|date',
            'coverage_end_date' => 'nullable|date',
            'certificate_number' => 'nullable|string|max:255',
            'certificate_date_issued' => 'nullable|date',
        ]);
        $data = $this->uppercaseStrings($validated);
        $enrollee = Enrollee::create($data);
        // Save health insurance
        $insuranceData['principal_id'] = $enrollee->id;
        $insuranceData = $this->uppercaseStrings($insuranceData);
        HealthInsurance::create($insuranceData);
        // ...existing code for dependents (if needed)...
        $enrollee->load(['dependents', 'healthInsurance']);
        return response()->json($enrollee, 201);
    }

    public function show($id)
    {
        $enrollee = Enrollee::with(['dependents', 'healthInsurance'])->findOrFail($id);
        return response()->json($enrollee);
    }

    // Update an enrollee and its dependents
    public function update(Request $request, $id)
    {
        $enrollee = Enrollee::findOrFail($id);
        $validated = $request->validate([
            'employee_id' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'birth_date' => 'required|date',
            'gender' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'with_dependents' => 'nullable|boolean',
            'enrollment_status' => 'required|string',
            'email1' => 'required|email',
            'email2' => 'nullable|email',
            'phone1' => 'nullable|string|max:255',
            'phone2' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'employment_start_date' => 'nullable|date',
            'employment_end_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'status' => 'required|string|in:ACTIVE,INACTIVE',
            'enrollment_id' => 'required',
        ]);
        $insuranceData = $request->validate([
            'plan' => 'nullable|string|max:255',
            'premium' => 'nullable|numeric',
            'principal_mbl' => 'nullable|numeric',
            'principal_room_and_board' => 'nullable|string|max:255',
            'dependent_mbl' => 'nullable|numeric',
            'dependent_room_and_board' => 'nullable|string|max:255',
            'is_renewal' => 'nullable|boolean',
            'is_company_paid' => 'nullable|boolean',
            'coverage_start_date' => 'nullable|date',
            'coverage_end_date' => 'nullable|date',
            'certificate_number' => 'nullable|string|max:255',
            'certificate_date_issued' => 'nullable|date',
        ]);
        $data = $this->uppercaseStrings($validated);
        $enrollee->update($data);
        // Save or update health insurance
        $insuranceData['principal_id'] = $enrollee->id;
        $insurance = HealthInsurance::where('principal_id', $enrollee->id)->first();
        $insuranceData = $this->uppercaseStrings($insuranceData);
        if ($insurance) {
            $insurance->update($insuranceData);
        } else {
            HealthInsurance::create($insuranceData);
        }

        // If is_company_paid is present, update all dependents' health insurance to match
        if (array_key_exists('is_company_paid', $insuranceData)) {
            $dependents = $enrollee->dependents;
            foreach ($dependents as $dependent) {
                $depInsurance = HealthInsurance::where('dependent_id', $dependent->id)->first();
                if ($depInsurance) {
                    $depInsurance->is_company_paid = $insuranceData['is_company_paid'];
                    $depInsurance->save();
                }
            }
        }
        // ...existing code for dependents (if needed)...
        $enrollee->load(['dependents', 'healthInsurance']);
        return response()->json($enrollee);
    }

    // Soft delete an enrollee and its dependents, with password check
    public function destroy(Request $request, $id)
    {
        $user = $this->validateDeletePassword($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }
        $enrollee = Enrollee::findOrFail($id);
        $enrollee->dependents()->delete();
        // Set deleted_by before soft delete
        $enrollee->deleted_by = auth()->id() ?? ($user->id ?? null);
        $enrollee->save();
        $enrollee->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
