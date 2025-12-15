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

        // Get action logs for all enrollees in this enrollment
        $actionLogs = ActionLog::where('model_type', 'Modules\ClientMasterlist\App\Models\Enrollee')
            ->whereIn('model_id', $enrolleeIds)
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
                    'metadata' => $log->metadata,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $actionLogs
        ]);
    }

    /**
     * Get all action logs for a specific enrollment (max 100)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllForEnrollment(Request $request): JsonResponse
    {
        $enrollmentId = $request->query('enrollment_id');

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

        // Get action logs for all enrollees in this enrollment
        $actionLogs = ActionLog::where('model_type', 'Modules\ClientMasterlist\App\Models\Enrollee')
            ->whereIn('model_id', $enrolleeIds)
            ->orderBy('created_at', 'desc')
            ->limit(100)
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
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'metadata' => $log->metadata,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $actionLogs,
            'total' => $actionLogs->count()
        ]);
    }
}
