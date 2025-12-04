<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Dependent;
use Modules\ClientMasterlist\App\Models\HealthInsurance;
use Modules\ClientMasterlist\App\Models\Enrollee;

use App\Http\Traits\UppercaseInput;
use App\Http\Traits\LogsActions;

class DependentController extends Controller
{
    use UppercaseInput, LogsActions;

    /**
     * Get all dependents for a principal
     */
    public function index(Request $request)
    {
        $principal_id = $request->query('principal_id');

        return Dependent::with('healthInsurance')
            ->where('principal_id', $principal_id)
            ->whereNull('deleted_at')
            ->get();
    }

    /**
     * Create a new dependent
     */
    public function store(Request $request)
    {
        $dependentData = $this->validateDependentData($request, true);
        $insuranceData = $this->validateInsuranceData($request);

        // Process business logic
        $this->setEmployeeIdFromPrincipal($dependentData);
        $this->processInsuranceLogic($insuranceData, $dependentData);

        // Create dependent
        $dependentData = $this->uppercaseStrings($dependentData);
        $dependent = Dependent::create($dependentData);

        // Handle insurance
        $this->handleDependentInsurance($dependent, $insuranceData);

        // Update principal timestamp
        $this->updatePrincipalTimestamp($dependent);
        
        // Log the create action
        $this->logCreate($dependent, [
            'principal_id' => $dependentData['principal_id'],
            'insurance_data' => $insuranceData
        ]);

        return $dependent->load('healthInsurance');
    }

    /**
     * Update an existing dependent
     */
    public function update(Request $request, $id)
    {
        $dependent = Dependent::findOrFail($id);
        $oldValues = $dependent->toArray();
        $dependentData = $this->validateDependentData($request, false);
        $insuranceData = $this->validateInsuranceData($request);

        // Store original values for comparison
        $originalData = $dependent->toArray();

        // Process business logic
        $this->setEmployeeIdFromPrincipal($dependentData, $dependent);
        $this->processInsuranceLogic($insuranceData, $dependentData);

        // Update dependent only if there are changes
        $dependentData = $this->uppercaseStrings($dependentData);

        // Check if there are actual changes to prevent unnecessary updated_at updates
        $hasChanges = false;
        foreach ($dependentData as $key => $value) {
            if ($dependent->getAttribute($key) != $value) {
                $hasChanges = true;
                break;
            }
        }

        if ($hasChanges) {
            $dependent->update($dependentData);
        }

        // Handle insurance
        $this->handleDependentInsurance($dependent, $insuranceData);

        // Update principal timestamp only if dependent data changed
        if ($hasChanges) {
            $this->updatePrincipalTimestamp($dependent, $originalData);
        }
        
        // Log the update action
        $this->logUpdate($dependent, $oldValues, [
            'insurance_data' => $insuranceData,
            'had_changes' => $hasChanges
        ]);

        return $dependent->load('healthInsurance');
    }

    /**
     * Validate dependent data
     */
    private function validateDependentData(Request $request, bool $requirePrincipalId = false): array
    {
        $rules = [
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
        ];

        if ($requirePrincipalId) {
            $rules['principal_id'] = 'required|integer';
        }

        return $request->validate($rules);
    }

    /**
     * Validate insurance data
     */
    private function validateInsuranceData(Request $request): array
    {
        return $request->validate([
            'is_skipping' => 'nullable|boolean',
            'reason_for_skipping' => 'nullable|string|max:255',
            'certificate_number' => 'nullable|string|max:255',
            'coverage_start_date' => 'nullable|date',
            'coverage_end_date' => 'nullable|date',
            'certificate_date_issued' => 'nullable|date',
            'is_company_paid' => 'nullable',
        ]);
    }

    /**
     * Set employee_id from principal if not provided
     */
    private function setEmployeeIdFromPrincipal(array &$dependentData, ?Dependent $existingDependent = null): void
    {
        if (empty($dependentData['employee_id'])) {
            $principalId = $dependentData['principal_id'] ?? $existingDependent?->principal_id;

            if ($principalId) {
                $principal = Enrollee::find($principalId);
                if ($principal?->employee_id) {
                    $dependentData['employee_id'] = $principal->employee_id;
                }
            }
        }
    }

    /**
     * Process insurance business logic
     */
    private function processInsuranceLogic(array &$insuranceData, array &$dependentData): void
    {
        // Convert is_company_paid
        $this->convertIsCompanyPaid($insuranceData);

        // Auto-set dates and status if certificate number exists
        if (!empty($insuranceData['certificate_number'])) {
            $this->setAutomaticInsuranceDates($insuranceData);
            $dependentData['enrollment_status'] = 'APPROVED';
        }
    }

    /**
     * Convert is_company_paid from various formats to boolean
     */
    private function convertIsCompanyPaid(array &$insuranceData): void
    {
        if (isset($insuranceData['is_company_paid'])) {
            $value = strtoupper(trim((string) $insuranceData['is_company_paid']));
            $insuranceData['is_company_paid'] = in_array($value, ['YES', '1', 1], true) ? 1 : 0;
        }
    }

    /**
     * Set automatic insurance dates when certificate number is provided
     */
    private function setAutomaticInsuranceDates(array &$insuranceData): void
    {
        if (empty($insuranceData['coverage_start_date'])) {
            $insuranceData['coverage_start_date'] = date('Y-m-d', strtotime('first day of next month'));
        }

        if (empty($insuranceData['certificate_date_issued'])) {
            $insuranceData['certificate_date_issued'] = $insuranceData['coverage_start_date'];
        }
    }

    /**
     * Handle dependent insurance creation/update
     */
    private function handleDependentInsurance(Dependent $dependent, array $insuranceData): void
    {
        // Set is_company_paid from principal's insurance if not set
        $this->inheritCompanyPaidFromPrincipal($insuranceData, $dependent->principal_id);

        // Prepare insurance data
        $insuranceData['dependent_id'] = $dependent->id;
        $insuranceData = $this->uppercaseStrings($insuranceData);

        // Create or update insurance
        $existingInsurance = HealthInsurance::where('dependent_id', $dependent->id)->first();

        if ($existingInsurance) {
            $existingInsurance->update($insuranceData);
        } else {
            HealthInsurance::create($insuranceData);
        }

        // Sync is_company_paid with principal's insurance
        $this->syncCompanyPaidWithPrincipal($insuranceData, $dependent->principal_id);
    }

    /**
     * Inherit is_company_paid from principal's insurance if not set
     */
    private function inheritCompanyPaidFromPrincipal(array &$insuranceData, int $principalId): void
    {
        if (!isset($insuranceData['is_company_paid'])) {
            $principalInsurance = HealthInsurance::where('principal_id', $principalId)->first();
            if ($principalInsurance?->is_company_paid !== null) {
                $insuranceData['is_company_paid'] = $principalInsurance->is_company_paid;
            }
        }
    }

    /**
     * Sync is_company_paid with principal's insurance
     */
    private function syncCompanyPaidWithPrincipal(array $insuranceData, int $principalId): void
    {
        if (isset($insuranceData['is_company_paid'])) {
            $principalInsurance = HealthInsurance::where('principal_id', $principalId)->first();
            if ($principalInsurance) {
                $principalInsurance->update([
                    'is_company_paid' => $insuranceData['is_company_paid']
                ]);
            }
        }
    }

    /**
     * Update principal's updated_at timestamp
     */
    private function updatePrincipalTimestamp(Dependent $dependent, array $originalData = []): void
    {
        $principalId = $dependent->principal_id ?? null;

        if ($principalId) {
            $principal = Enrollee::find($principalId);

            // Only update principal timestamp if there are meaningful changes
            $shouldUpdateTimestamp = false;

            // Check if enrollment_status or status changed
            if (!empty($originalData)) {
                $statusChanged = ($originalData['enrollment_status'] ?? null) !== $dependent->enrollment_status;
                $activeStatusChanged = ($originalData['status'] ?? null) !== $dependent->status;
                $shouldUpdateTimestamp = $statusChanged || $activeStatusChanged;
            } else {
                // For new dependents (store method), always update
                $shouldUpdateTimestamp = true;
            }

            if ($shouldUpdateTimestamp && $principal) {
                if (($dependent->enrollment_status === 'APPROVED' && $dependent->status === 'ACTIVE') || $dependent->enrollment_status !== 'APPROVED') {
                    $principal->touch();
                }
            }
        }
    }

    /**
     * Soft delete a dependent
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $dependent = Dependent::withTrashed()->find($id);

        if (!$dependent) {
            return response()->json([
                'success' => false,
                'message' => 'Dependent not found'
            ], 404);
        }

        if ($dependent->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Dependent already deleted'
            ], 410);
        }

        // Set deleted_by if user is authenticated
        if (auth()->check()) {
            $dependent->deleted_by = auth()->id();
            $dependent->save();
        }
        
        // Log the delete action
        $this->logDelete($dependent, [
            'deleted_by' => auth()->id(),
            'principal_id' => $dependent->principal_id
        ]);

        $dependent->delete();

        // Update principal timestamp (for deletion, always update)
        if ($dependent->principal_id) {
            $principal = Enrollee::find($dependent->principal_id);
            if ($principal) {
                $principal->touch();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Dependent soft deleted'
        ]);
    }
}
