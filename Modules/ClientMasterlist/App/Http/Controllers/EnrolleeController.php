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
        $enrollmentFilter = $request->query('enrollment_filter');
        $enrollmentStatus = $request->query('enrollment_status');
        $search = $request->query('search');
        $perPage = $request->query('per_page', 20);

        $query = Enrollee::with(['dependents', 'healthInsurance'])
            ->whereNull('deleted_at'); // Apply soft delete filter first

        // Apply enrollment_id filter
        if ($enrollmentId) {
            $query->where('enrollment_id', $enrollmentId);
        }

        // Handle enrollment status with proper grouping for OR conditions
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

        // Apply search filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%$search%")
                    ->orWhere('last_name', 'like', "%$search%")
                    ->orWhere('employee_id', 'like', "%$search%");
            });
        }

        // Apply renewal filter
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
                    //$subQ->where('is_renewal', true);
                });
            });
        }

        // Apply ordering
        $query->orderBy('updated_at', 'desc');

        $enrollees = $query->paginate($perPage);

        // Append query parameters to pagination links
        $enrollees->appends($request->query());

        return response()->json($enrollees);
    }

    // Get all enrollees for select dropdown
    public function getAllForSelect(Request $request)
    {
        $enrollmentId = $request->query('enrollment_id');
        $enrollmentStatus = $request->query('enrollment_status');
        $search = $request->query('search');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $query = Enrollee::select('id', 'employee_id', 'first_name', 'last_name', 'middle_name', 'enrollment_status', 'enrollment_id', 'email1');

        // Apply enrollment_id filter
        if ($enrollmentId) {
            $query->where('enrollment_id', $enrollmentId);
        }

        // Apply enrollment status filter
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

        // Apply search filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%$search%")
                    ->orWhere('last_name', 'like', "%$search%")
                    ->orWhere('employee_id', 'like', "%$search%");
            });
        }

        // Apply date range filter on updated_at
        if ($dateFrom) {
            $query->whereDate('updated_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('updated_at', '<=', $dateTo);
        }

        $enrollees = $query->whereNull('deleted_at')
            ->where('status', 'ACTIVE')
            ->orderBy('first_name', 'asc')
            ->orderBy('last_name', 'asc')
            ->get()
            ->map(function ($enrollee) {
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
            });

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
