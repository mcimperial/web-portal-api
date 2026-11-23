<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Connectivity check for both module databases
 * (llibiapp_sync + llibiapp_portal).
 */
class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $connections = [
            'sync' => config('clientmobiledata.sync_connection', 'mysql_sync'),
            'portal' => config('clientmobiledata.portal_connection', 'mysql_masterlist'),
        ];

        $status = [];
        $healthy = true;

        foreach ($connections as $label => $connection) {
            try {
                DB::connection($connection)->getPdo();

                $status[$label] = [
                    'connected' => true,
                    'database' => DB::connection($connection)->getDatabaseName(),
                ];
            } catch (\Throwable $e) {
                $healthy = false;

                $status[$label] = [
                    'connected' => false,
                    'error' => 'Connection failed.',
                ];
            }
        }

        return response()->json([
            'module' => 'ClientMobileData',
            'healthy' => $healthy,
            'connections' => $status,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
