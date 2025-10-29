<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Dependent;
use Modules\ClientMasterlist\App\Models\HealthInsurance;

use App\Http\Traits\UppercaseInput;

class DependentController extends Controller
{
    use UppercaseInput;

    public function index(Request $request)
    {
        $principal_id = $request->query('principal_id');
        // Only get dependents that are not soft deleted
        return Dependent::with('healthInsurance')->where('principal_id', $principal_id)->whereNull('deleted_at')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'principal_id' => 'required|integer',
            'employee_id' => 'nullable|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'relation' => 'required|string|max:255',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|string|max:255',
            'nationality' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'member_id' => 'nullable|string|max:255',
            'enrollment_status' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
        ]);
        // If employee_id is not provided, get it from principal
        if (empty($validated['employee_id'])) {
            $principal = \Modules\ClientMasterlist\App\Models\Enrollee::find($validated['principal_id']);
            if ($principal && isset($principal->employee_id)) {
                $validated['employee_id'] = $principal->employee_id;
            }
        }
        $insuranceData = $request->validate([
            'is_skipping' => 'nullable|boolean',
            'reason_for_skipping' => 'nullable|string|max:255',
            'certificate_number' => 'nullable|string|max:255',
            'coverage_start_date' => 'nullable|date',
            'coverage_end_date' => 'nullable|date',
            'certificate_date_issued' => 'nullable|date',
        ]);
        // Convert is_company_paid from 'YES'/'NO' to 1/0 if present
        if (isset($insuranceData['is_company_paid'])) {
            $value = strtoupper(trim($insuranceData['is_company_paid']));
            $insuranceData['is_company_paid'] = ($value === 'YES' || $value === '1' || $value === 1) ? 1 : 0;
        }
        $validated = $this->uppercaseStrings($validated);
        $dependent = Dependent::create($validated);

        // Set is_company_paid from principal's health insurance if available
        if (!empty($validated['principal_id'])) {
            $principalInsurance = HealthInsurance::where('principal_id', $validated['principal_id'])->first();
            if ($principalInsurance && isset($principalInsurance->is_company_paid)) {
                $insuranceData['is_company_paid'] = $principalInsurance->is_company_paid;
            }
        }
        // Save health insurance
        $insuranceData['dependent_id'] = $dependent->id;
        $insuranceData = $this->uppercaseStrings($insuranceData);
        HealthInsurance::create($insuranceData);

        // If is_company_paid is present, update principal's health insurance to match
        if (isset($insuranceData['is_company_paid']) && !empty($validated['principal_id'])) {
            $principalInsurance = HealthInsurance::where('principal_id', $validated['principal_id'])->first();
            if ($principalInsurance) {
                $principalInsurance->is_company_paid = $insuranceData['is_company_paid'];
                $principalInsurance->save();
            }
        }

        // Update principal's updated_at timestamp when dependent is created
        if (!empty($validated['principal_id'])) {
            $principal = \Modules\ClientMasterlist\App\Models\Enrollee::find($validated['principal_id']);
            if ($principal) {
                $principal->touch();
            }
        }

        $dependent->load('healthInsurance');
        return $dependent;
    }

    public function update(Request $request, $id)
    {
        $dependent = Dependent::findOrFail($id);

        $validated = $request->validate([
            'employee_id' => 'nullable|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'relation' => 'required|string|max:255',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|string|max:255',
            'nationality' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'member_id' => 'nullable|string|max:255',
            'enrollment_status' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
        ]);
        // If principal_employee_id is not provided, get it from principal
        if (empty($validated['employee_id']) && isset($dependent->principal_id)) {
            $principal = \Modules\ClientMasterlist\App\Models\Enrollee::find($dependent->principal_id);
            if ($principal && isset($principal->employee_id)) {
                $validated['employee_id'] = $principal->employee_id;
            }
        }
        $insuranceData = $request->validate([
            'is_skipping' => 'nullable|boolean',
            'reason_for_skipping' => 'nullable|string|max:255',
            'certificate_number' => 'nullable|string|max:255',
            'coverage_start_date' => 'nullable|date',
            'coverage_end_date' => 'nullable|date',
            'certificate_date_issued' => 'nullable|date',
        ]);
        // Convert is_company_paid from 'YES'/'NO' to 1/0 if present
        if (isset($insuranceData['is_company_paid'])) {
            $value = strtoupper(trim($insuranceData['is_company_paid']));
            $insuranceData['is_company_paid'] = ($value === 'YES' || $value === '1' || $value === 1) ? 1 : 0;
        }
        $validated = $this->uppercaseStrings($validated);
        $dependent->update($validated);
        // Set is_company_paid from principal's health insurance if available
        if (isset($dependent->principal_id)) {
            $principalInsurance = HealthInsurance::where('principal_id', $dependent->principal_id)->first();
            if ($principalInsurance && isset($principalInsurance->is_company_paid)) {
                $insuranceData['is_company_paid'] = $principalInsurance->is_company_paid;
            }
        }
        // Save or update health insurance
        $insuranceData['dependent_id'] = $dependent->id;
        $insurance = HealthInsurance::where('dependent_id', $dependent->id)->first();

        $insuranceData = $this->uppercaseStrings($insuranceData);
        if ($insurance) {
            $insurance->update($insuranceData);
        } else {
            HealthInsurance::create($insuranceData);
        }

        // If is_company_paid is present, update principal's health insurance to match
        if (isset($insuranceData['is_company_paid']) && isset($dependent->principal_id)) {
            $principalInsurance = HealthInsurance::where('principal_id', $dependent->principal_id)->first();
            if ($principalInsurance) {
                $principalInsurance->is_company_paid = $insuranceData['is_company_paid'];
                $principalInsurance->save();
            }
        }

        // Update principal's updated_at timestamp when dependent is updated
        if (isset($dependent->principal_id)) {
            $principal = \Modules\ClientMasterlist\App\Models\Enrollee::find($dependent->principal_id);
            if ($principal) {
                $principal->touch();
            }
        }

        $dependent->load('healthInsurance');
        return $dependent;
    }

    public function destroy(Request $request, $id)
    {
        $dependent = Dependent::withTrashed()->find($id);
        if (!$dependent) {
            return response()->json(['success' => false, 'message' => 'Dependent not found'], 404);
        }
        if ($dependent->trashed()) {
            return response()->json(['success' => false, 'message' => 'Dependent already deleted'], 410);
        }
        // Set deleted_by to current user if available
        if (auth()->check()) {
            $dependent->deleted_by = auth()->id();
            $dependent->save();
        }

        $dependent->delete();

        // Update principal's updated_at timestamp when dependent is deleted
        if (isset($dependent->principal_id)) {
            $principal = \Modules\ClientMasterlist\App\Models\Enrollee::find($dependent->principal_id);
            if ($principal) {
                $principal->touch();
            }
        }

        return response()->json(['success' => true, 'message' => 'Dependent soft deleted']);
    }
}
