<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ClientMobileApp\App\Models\Doctor;

/**
 * Accredited doctors from llibiapp_sync.doctors (+ doctors_clinics).
 */
class DoctorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'specialization' => ['sometimes', 'string', 'max:255'],
            'hospital_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('clientmobiledata.max_per_page', 100)],
        ]);

        $doctors = Doctor::query()
            ->select(Doctor::PUBLIC_COLUMNS)
            ->where('status', 1)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.trim($request->search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('first', 'like', $term)
                        ->orWhere('last', 'like', $term)
                        ->orWhere('specialization', 'like', $term)
                        ->orWhere('keywords', 'like', $term);
                });
            })
            ->when($request->filled('specialization'), function ($q) use ($request) {
                $term = '%'.trim($request->specialization).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('specialization', 'like', $term)
                        ->orWhere('specialization2', 'like', $term);
                });
            })
            ->when($request->filled('hospital_id'), function ($q) use ($request) {
                $q->whereHas('clinics', function ($sub) use ($request) {
                    $sub->where('hospital_id', $request->integer('hospital_id'))
                        ->where('status', 1);
                });
            })
            ->orderBy('last')
            ->paginate($request->integer('per_page', config('clientmobiledata.per_page', 20)));

        return response()->json($doctors);
    }

    public function show(int $id): JsonResponse
    {
        $doctor = Doctor::query()
            ->select(Doctor::PUBLIC_COLUMNS)
            ->with(['clinics' => function ($q) {
                $q->where('status', 1)
                    ->select(['id', 'hospital_id', 'doctors_id', 'schedule'])
                    ->with('hospital:id,name,city,add1,add2,add3,phone1');
            }])
            ->find($id);

        if (! $doctor) {
            return response()->json(['message' => 'Doctor not found.'], 404);
        }

        return response()->json(['doctor' => $doctor]);
    }
}
