<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ClientMobileApp\App\Models\MobileComplaint;
use Modules\ClientMobileApp\App\Models\PortalComplaint;

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

    public function customIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $complaints = MobileComplaint::query()
            ->select(['id', 'title'])
            ->when(
                ! empty($validated['search']),
                fn ($query) => $query->where('title', 'like', '%'.trim($validated['search']).'%')
            )
            ->orderBy('title')
            ->paginate($request->integer('per_page', 30));

        $complaints->getCollection()->transform(fn (MobileComplaint $complaint) => [
            'id' => $complaint->id,
            'title' => $complaint->title,
            'source' => 'custom',
        ]);

        return response()->json($complaints);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
        ]);

        $title = trim($validated['title']);
        $normalizedTitle = mb_strtolower($title);
        $existing = PortalComplaint::query()
            ->where('is_status', 1)
            ->whereRaw('LOWER(TRIM(title)) = ?', [$normalizedTitle])
            ->orderBy('id')
            ->first();

        if ($existing) {
            return response()->json([
                'complaint' => ['id' => $existing->id, 'title' => $existing->title, 'source' => 'catalog'],
            ]);
        }

        $complaint = MobileComplaint::query()->firstOrCreate(
            ['normalized_title' => $normalizedTitle],
            ['title' => $title]
        );

        return response()->json([
            'complaint' => ['id' => $complaint->id, 'title' => $complaint->title, 'source' => 'custom'],
        ], $complaint->wasRecentlyCreated ? 201 : 200);
    }
}
