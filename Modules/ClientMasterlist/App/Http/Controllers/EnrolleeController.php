<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\HealthInsurance;
use App\Http\Traits\UppercaseInput;
use App\Http\Traits\PasswordDeleteValidation;

class EnrolleeController extends Controller
{
    use UppercaseInput, PasswordDeleteValidation;

    /**
     * List all enrollees for an enrollment with filtering and pagination
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->extractIndexFilters($request);
        $query = $this->buildEnrolleeQuery($filters);

        $enrollees = $query->paginate($filters['per_page']);
        $enrollees->appends($request->query());

        return response()->json($enrollees);
    }

    /**
     * Get all enrollees for select dropdown
     */
    public function getAllForSelect(Request $request): JsonResponse
    {
        $filters = $this->extractSelectFilters($request);
        $query = $this->buildSelectQuery($filters);

        $enrollees = $this->formatSelectEnrollees($query->get());

        return response()->json($enrollees);
    }

    /**
     * Show a specific enrollee
     */
    public function show($id): JsonResponse
    {
        $enrollee = Enrollee::with(['dependents', 'healthInsurance'])->findOrFail($id);
        return response()->json($enrollee);
    }

    /**
     * Store a new enrollee with health insurance
     */
    public function store(Request $request): JsonResponse
    {
        $enrolleeData = $this->validateEnrolleeData($request);
        $insuranceData = $this->validateInsuranceData($request);

        // Process business logic
        $this->processEnrollmentLogic($enrolleeData, $insuranceData);
        $this->processEmploymentStatus($enrolleeData, $insuranceData);

        // Create enrollee
        $enrolleeData = $this->uppercaseStrings($enrolleeData);
        $enrollee = Enrollee::create($enrolleeData);

        // Handle insurance
        $this->createEnrolleeInsurance($enrollee, $insuranceData);

        return response()->json($enrollee->load(['dependents', 'healthInsurance']), 201);
    }

    /**
     * Update an existing enrollee and its health insurance
     */
    public function update(Request $request, $id): JsonResponse
    {
        $enrollee = Enrollee::findOrFail($id);
        $enrolleeData = $this->validateEnrolleeData($request);
        $insuranceData = $this->validateInsuranceData($request);

        // Process business logic
        $this->processEnrollmentLogic($enrolleeData, $insuranceData);
        $this->processEmploymentStatus($enrolleeData, $insuranceData);

        // Update enrollee only if there are changes
        $enrolleeData = $this->uppercaseStrings($enrolleeData);

        // Check if there are actual changes to prevent unnecessary updated_at updates
        $hasChanges = false;
        foreach ($enrolleeData as $key => $value) {
            if ($enrollee->getAttribute($key) != $value) {
                $hasChanges = true;
                break;
            }
        }

        if ($hasChanges) {
            $enrollee->update($enrolleeData);
        }

        // Handle insurance
        $this->updateEnrolleeInsurance($enrollee, $insuranceData);

        return response()->json($enrollee->load(['dependents', 'healthInsurance']));
    }

    /**
     * Soft delete an enrollee and its dependents with password validation
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $this->validateDeletePassword($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $enrollee = Enrollee::findOrFail($id);

        // Soft delete dependents first
        $enrollee->dependents()->delete();

        // Set deleted_by and soft delete enrollee
        $enrollee->deleted_by = auth()->id() ?? $user->id ?? null;
        $enrollee->save();
        $enrollee->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Extract filters for index method
     */
    private function extractIndexFilters(Request $request): array
    {
        return [
            'enrollment_id' => $request->query('enrollment_id'),
            'enrollment_filter' => $request->query('enrollment_filter'),
            'enrollment_status' => $request->query('enrollment_status'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', 20),
        ];
    }

    /**
     * Extract filters for select method
     */
    private function extractSelectFilters(Request $request): array
    {
        return [
            'enrollment_id' => $request->query('enrollment_id'),
            'enrollment_status' => $request->query('enrollment_status'),
            'search' => $request->query('search'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];
    }

    /**
     * Build query for enrollee index with filters
     */
    private function buildEnrolleeQuery(array $filters)
    {
        $query = Enrollee::with(['dependents', 'healthInsurance'])
            ->whereNull('deleted_at');

        $this->applyEnrollmentIdFilter($query, $filters['enrollment_id']);
        $this->applyEnrollmentStatusFilter($query, $filters['enrollment_status']);
        $this->applySearchFilter($query, $filters['search']);
        $this->applyEnrollmentFilter($query, $filters['enrollment_filter']);

        return $query->orderBy('updated_at', 'desc');
    }

    /**
     * Build query for select dropdown
     */
    private function buildSelectQuery(array $filters)
    {
        $query = Enrollee::select('id', 'employee_id', 'first_name', 'last_name', 'middle_name', 'enrollment_status', 'enrollment_id', 'email1');

        $this->applyEnrollmentIdFilter($query, $filters['enrollment_id']);
        $this->applyEnrollmentStatusFilter($query, $filters['enrollment_status']);
        $this->applySearchFilter($query, $filters['search']);
        $this->applyDateRangeFilter($query, $filters['date_from'], $filters['date_to']);

        return $query->whereNull('deleted_at')
            //->where('status', 'ACTIVE')
            ->orderBy('first_name', 'asc')
            ->orderBy('last_name', 'asc');
    }

    /**
     * Apply enrollment ID filter
     */
    private function applyEnrollmentIdFilter($query, ?string $enrollmentId): void
    {
        if ($enrollmentId) {
            $query->where('enrollment_id', $enrollmentId);
        }
    }

    /**
     * Apply enrollment status filter
     */
    private function applyEnrollmentStatusFilter($query, ?string $enrollmentStatus): void
    {
        if ($enrollmentStatus) {
            if ($enrollmentStatus === 'FOR-RENEWAL') {
                $query->where(function ($q) use ($enrollmentStatus) {
                    $q->where('enrollment_status', $enrollmentStatus)
                        ->orWhereHas('healthInsurance', function ($subQ) {
                            $subQ->where('is_renewal', true);
                        });
                });
            } else {
                $query->where('enrollment_status', $enrollmentStatus);
            }
        }
    }

    /**
     * Apply search filter
     */
    private function applySearchFilter($query, ?string $search): void
    {
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%$search%")
                    ->orWhere('last_name', 'like', "%$search%")
                    ->orWhere('employee_id', 'like', "%$search%")
                    ->orWhereHas('dependents', function ($subQ) use ($search) {
                        $subQ->where('first_name', 'like', "%$search%")
                            ->orWhere('last_name', 'like', "%$search%");
                    })
                    ->orWhereHas('healthInsurance', function ($subQ) use ($search) {
                        $subQ->where('certificate_number', 'like', "%$search%");
                    })
                    ->orWhereHas('dependents.healthInsurance', function ($subQ) use ($search) {
                        $subQ->where('certificate_number', 'like', "%$search%");
                    });
            });
        }
    }

    /**
     * Apply enrollment filter (renewal/regular)
     */
    private function applyEnrollmentFilter($query, ?string $enrollmentFilter): void
    {
        if ($enrollmentFilter) {
            $query->where(function ($q) use ($enrollmentFilter) {
                $q->orWhereHas('healthInsurance', function ($subQ) use ($enrollmentFilter) {
                    if ($enrollmentFilter === 'REGULAR') {
                        $subQ->where(function ($sq) {
                            $sq->where('is_renewal', false)
                                ->orWhereNull('is_renewal');
                        });
                    } else if ($enrollmentFilter === 'RENEWAL') {
                        $subQ->where('is_renewal', true);
                    }
                });
            });
        }
    }

    /**
     * Apply date range filter
     */
    private function applyDateRangeFilter($query, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom) {
            $query->whereDate('updated_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('updated_at', '<=', $dateTo);
        }
    }

    /**
     * Format enrollees for select dropdown
     */
    private function formatSelectEnrollees($enrollees): array
    {
        return $enrollees->map(function ($enrollee) {
            return [
                'id' => $enrollee->id,
                'employee_id' => $enrollee->employee_id,
                'first_name' => $enrollee->first_name,
                'last_name' => $enrollee->last_name,
                'middle_name' => $enrollee->middle_name,
                'enrollment_status' => $enrollee->enrollment_status,
                'enrollment_id' => $enrollee->enrollment_id,
                'email1' => $enrollee->email1,
            ];
        })->toArray();
    }

    /**
     * Validate enrollee data
     */
    private function validateEnrolleeData(Request $request): array
    {
        return $request->validate([
            'employee_id' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'suffix' => 'nullable|string|max:255',
            'birth_date' => 'required|date',
            'gender' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'with_dependents' => 'nullable|boolean',
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
            'enrollment_status' => 'required|string',
            'status' => 'required|string|in:ACTIVE,INACTIVE',
            'enrollment_id' => 'required',
        ]);
    }

    /**
     * Validate insurance data
     */
    private function validateInsuranceData(Request $request): array
    {
        return $request->validate([
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
    }

    /**
     * Process enrollment logic (certificate number and approval)
     */
    private function processEnrollmentLogic(array &$enrolleeData, array &$insuranceData): void
    {
        if (
            !empty($insuranceData['certificate_number']) &&
            $enrolleeData['enrollment_status'] !== 'APPROVED' &&
            !($insuranceData['is_renewal'] ?? false)
        ) {

            $insuranceData['certificate_date_issued'] = date('Y-m-d');
            $enrolleeData['enrollment_status'] = 'APPROVED';

            if (empty($insuranceData['coverage_start_date'])) {
                $insuranceData['coverage_start_date'] = $this->getFirstDayOfNextMonth();
            }
        }
    }

    /**
     * Process employment status based on end dates
     */
    private function processEmploymentStatus(array &$enrolleeData, array &$insuranceData): void
    {
        $hasEmploymentEndDate = !empty($enrolleeData['employment_end_date']);
        $hasCoverageEndDate = !empty($insuranceData['coverage_end_date']);

        if ($hasEmploymentEndDate || $hasCoverageEndDate) {
            $today = date('Y-m-d');

            if (($hasEmploymentEndDate && $enrolleeData['employment_end_date'] < $today) ||
                ($hasCoverageEndDate && $insuranceData['coverage_end_date'] < $today)
            ) {

                $enrolleeData['status'] = 'INACTIVE';
                $enrolleeData['enrollment_status'] = 'RESIGNED';
            }

            // Sync employment and coverage end dates
            /* if ($hasEmploymentEndDate) {
                $insuranceData['coverage_end_date'] = $enrolleeData['employment_end_date'];
            } */

            if ($hasCoverageEndDate) {
                $enrolleeData['employment_end_date'] = $insuranceData['coverage_end_date'];
            }
        }
    }

    /**
     * Create health insurance for enrollee
     */
    private function createEnrolleeInsurance(Enrollee $enrollee, array $insuranceData): void
    {
        $insuranceData['principal_id'] = $enrollee->id;
        $insuranceData = $this->uppercaseStrings($insuranceData);
        HealthInsurance::create($insuranceData);
    }

    /**
     * Update health insurance for enrollee
     */
    private function updateEnrolleeInsurance(Enrollee $enrollee, array $insuranceData): void
    {
        $insuranceData['principal_id'] = $enrollee->id;
        $insuranceData = $this->uppercaseStrings($insuranceData);

        $insurance = HealthInsurance::where('principal_id', $enrollee->id)->first();

        if ($insurance) {
            $insurance->update($insuranceData);
        } else {
            HealthInsurance::create($insuranceData);
        }

        // Sync is_company_paid with dependents if present
        $this->syncCompanyPaidWithDependents($enrollee, $insuranceData);
    }

    /**
     * Sync is_company_paid with all dependents' insurance
     */
    private function syncCompanyPaidWithDependents(Enrollee $enrollee, array $insuranceData): void
    {
        if (array_key_exists('is_company_paid', $insuranceData)) {
            $dependents = $enrollee->dependents;

            foreach ($dependents as $dependent) {
                $depInsurance = HealthInsurance::where('dependent_id', $dependent->id)->first();
                if ($depInsurance) {
                    $depInsurance->update([
                        'is_company_paid' => $insuranceData['is_company_paid']
                    ]);
                }
            }
        }
    }

    /**
     * Get the first day of next month
     */
    private function getFirstDayOfNextMonth(): string
    {
        return date('Y-m-d', strtotime('first day of next month'));
    }
}
