<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ClientMobileApp\App\Models\Hospital;

/**
 * Accredited hospitals / providers from llibiapp_sync.hospitals.
 */
class HospitalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'accept_eloa' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('clientmobiledata.max_per_page', 100)],
        ]);

        $hospitals = Hospital::query()
            ->select(Hospital::PUBLIC_COLUMNS)
            ->where('status', 1)
            ->where('accredited', 1)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.trim($request->search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('keywords', 'like', $term)
                        ->orWhere('city', 'like', $term);
                });
            })
            ->when($request->filled('city'), fn ($q) => $q->where('city', 'like', '%'.trim($request->city).'%'))
            ->when($request->has('accept_eloa'), fn ($q) => $q->where('accept_eloa', $request->boolean('accept_eloa')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', config('clientmobiledata.per_page', 20)));

        return response()->json($hospitals);
    }

    public function show(int $id): JsonResponse
    {
        $hospital = Hospital::query()
            ->select(Hospital::PUBLIC_COLUMNS)
            ->find($id);

        if (! $hospital) {
            return response()->json(['message' => 'Hospital not found.'], 404);
        }

        return response()->json(['hospital' => $hospital]);
    }

    /**
     * Doctors holding clinic at the given hospital.
     */
    public function doctors(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('clientmobiledata.max_per_page', 100)],
        ]);

        $hospital = Hospital::query()
            ->select(['id', 'name'])
            ->where('status', 1)
            ->where('accredited', 1)
            ->find($id);

        if (! $hospital) {
            return response()->json(['message' => 'Hospital not found.'], 404);
        }

        $doctors = $hospital->clinics()
            ->where('doctors_clinics.status', 1)
            ->join('doctors', 'doctors.id', '=', 'doctors_clinics.doctors_id')
            ->where('doctors.status', 1)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.trim($request->search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('doctors.first', 'like', $term)
                        ->orWhere('doctors.last', 'like', $term)
                        ->orWhere('doctors.specialization', 'like', $term)
                        ->orWhere('doctors.keywords', 'like', $term);
                });
            })
            ->select([
                'doctors.id', 'doctors.code', 'doctors.first', 'doctors.middle',
                'doctors.last', 'doctors.gender', 'doctors.specialization',
                'doctors.specialization2', 'doctors_clinics.schedule',
            ])
            ->orderBy('doctors.last')
            ->paginate($request->integer('per_page', config('clientmobiledata.per_page', 20)));

        return response()->json($doctors);
    }
}
