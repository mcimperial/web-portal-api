<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Models\ActionLog;
use Modules\ClientMasterlist\App\Models\Enrollee;

class ActionLogController extends Controller
{
    /**
     * Get the latest action logs for a specific enrollment
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getLatestForEnrollment(Request $request): JsonResponse
    {
        $enrollmentId = $request->query('enrollment_id');
        $limit = $request->query('limit', 3);

        if (!$enrollmentId) {
            return response()->json([
                'success' => false,
                'message' => 'Enrollment ID is required'
            ], 400);
        }

        // Get all enrollee IDs for this enrollment
        $enrolleeIds = Enrollee::where('enrollment_id', $enrollmentId)
            ->pluck('id')
            ->toArray();

        // Build the base query
        $query = ActionLog::where('model_type', 'Modules\ClientMasterlist\App\Models\Enrollee')
            ->whereIn('model_id', $enrolleeIds);

        // Get action logs for all enrollees in this enrollment
        $actionLogs = $query
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action_type' => $log->action_type,
                    'description' => $log->description,
                    'user_name' => $log->user_name,
                    'user_email' => $log->user_email,
                    'status' => $log->status,
                    'created_at' => $log->created_at,
                    'user_role' => $log->metadata['user_role'] ?? null,
                    'metadata' => $log->metadata,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $actionLogs
        ]);
    }

    /**
     * Get paginated action logs for a specific enrollment
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllForEnrollment(Request $request): JsonResponse
    {
        $enrollmentId = $request->query('enrollment_id');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', 10);
        $perPage = max(1, min($perPage, 100));
        $search = trim((string) $request->query('search', ''));

        if (!$enrollmentId) {
            return response()->json([
                'success' => false,
                'message' => 'Enrollment ID is required'
            ], 400);
        }

        // Get all enrollee IDs for this enrollment
        $enrolleeIds = Enrollee::where('enrollment_id', $enrollmentId)
            ->pluck('id')
            ->toArray();

        // Build the base query
        $query = ActionLog::where('model_type', 'Modules\ClientMasterlist\App\Models\Enrollee')
            ->whereIn('model_id', $enrolleeIds);

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search) {
                $likeSearch = '%' . $search . '%';

                $searchQuery->where('action_type', 'like', $likeSearch)
                    ->orWhere('status', 'like', $likeSearch)
                    ->orWhere('description', 'like', $likeSearch)
                    ->orWhere('user_name', 'like', $likeSearch)
                    ->orWhere('user_email', 'like', $likeSearch)
                    ->orWhereRaw('CAST(old_values AS CHAR) LIKE ?', [$likeSearch])
                    ->orWhereRaw('CAST(new_values AS CHAR) LIKE ?', [$likeSearch]);
            });
        }

        // Get paginated action logs for all enrollees in this enrollment
        $paginatedLogs = $query
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $actionLogs = collect($paginatedLogs->items())
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action_type' => $log->action_type,
                    'description' => $log->description,
                    'user_name' => $log->user_name,
                    'user_email' => $log->user_email,
                    'status' => $log->status,
                    'created_at' => $log->created_at,
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'user_role' => $log->metadata['user_role'] ?? null,
                    'metadata' => $log->metadata,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $actionLogs,
            'total' => $paginatedLogs->total(),
            'current_page' => $paginatedLogs->currentPage(),
            'last_page' => $paginatedLogs->lastPage(),
            'per_page' => $paginatedLogs->perPage(),
        ]);
    }
}
