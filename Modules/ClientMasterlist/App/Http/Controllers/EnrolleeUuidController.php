<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Models\Enrollee;

class EnrolleeUuidController extends Controller
{
    public function show($uuid)
    {
        $enrollee = Enrollee::with(['dependents.health_insurance', 'enrollment'])->where('uuid', $uuid)->first();

        if (!$enrollee) {
            return response()->json(['message' => 'Enrollee not found'], 404);
        }

        // Map dependents to include skip hierarchy fields from health_insurance
        $dependentsWithSkipHierarchy = collect($enrollee->dependents)->map(function ($dep) {
            $health = $dep->health_insurance;
            return [
                'id' => $dep->id,
                'first_name' => $dep->first_name,
                'last_name' => $dep->last_name,
                'middle_name' => $dep->middle_name,
                'relation' => $dep->relation,
                'birth_date' => $dep->birth_date,
                'gender' => $dep->gender,
                'marital_status' => $dep->marital_status,
                // ... add other dependent fields as needed ...
                'health_insurance' => $health,
                // Populate skip hierarchy fields from health_insurance if present
                'is_skipping' => $health ? $health->is_skipping : null,
                'reason_for_skipping' => $health ? $health->reason_for_skipping : null,
                'attachment_for_skipping' => $health ? $health->attachment_for_skipping : null,
            ];
        });

        // Return enrollee with dependents including skip hierarchy fields
        $enrolleeArr = $enrollee->toArray();
        $enrolleeArr['dependents'] = $dependentsWithSkipHierarchy;

        return response()->json($enrolleeArr);
    }

    public function update(Request $request, $uuid)
    {
        $enrollee = Enrollee::where('uuid', $uuid)->first();
        if (!$enrollee) {
            return response()->json(['message' => 'Enrollee not found'], 404);
        }

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'middle_name' => 'sometimes|string|max:255',
            'birth_date' => 'sometimes|date',
            'gender' => 'sometimes|string|max:255',
            'marital_status' => 'sometimes|string|max:255',
            'address' => 'nullable|string|max:255',
            'enrollment_status' => 'nullable|string'
        ]);

        $enrollee->fill($validated);
        $enrollee->save();

        return response()->json([
            'success' => true,
            'message' => 'Enrollee updated successfully',
            'enrollee' => $enrollee
        ]);
    }
}
