<?php

namespace Modules\ClientMobileData\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ClientMobileData\App\Models\PortalComplaint;

class ComplaintController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $complaints = PortalComplaint::query()
            ->selectRaw('MIN(id) as id, title')
            ->where('is_status', 1)
            ->when(
                ! empty($validated['search']),
                fn ($query) => $query->where('title', 'like', '%'.trim($validated['search']).'%')
            )
            ->groupBy('title')
            ->orderBy('title')
            ->paginate($request->integer('per_page', 30));

        return response()->json($complaints);
    }
}
