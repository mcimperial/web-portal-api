<?php

namespace Modules\ClientThirdParty\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SyncController extends Controller
{
    /**
     * Test the sync database connection
     */
    public function testConnection(): JsonResponse
    {
        try {
            DB::connection('mysql_sync')->getPdo();
            $databaseName = DB::connection('mysql_sync')->getDatabaseName();
            
            return response()->json([
                'success' => true,
                'message' => 'Successfully connected to sync database',
                'database' => $databaseName
            ]);
        } catch (Exception $e) {
            Log::error('Sync database connection failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect to sync database',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all tables from the sync database
     */
    public function getTables(): JsonResponse
    {
        try {
            $tables = DB::connection('mysql_sync')
                ->select('SHOW TABLES');
            
            $tableNames = array_map(function($table) {
                return array_values((array)$table)[0];
            }, $tables);
            
            return response()->json([
                'success' => true,
                'tables' => $tableNames,
                'count' => count($tableNames)
            ]);
        } catch (Exception $e) {
            Log::error('Failed to get tables: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve tables',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get data from a specific table
     */
    public function getTableData(Request $request, string $table): JsonResponse
    {
        try {
            // Validate table name to prevent SQL injection
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid table name'
                ], 400);
            }

            $perPage = $request->input('per_page', 15);
            $page = $request->input('page', 1);
            
            $data = DB::connection('mysql_sync')
                ->table($table)
                ->paginate($perPage, ['*'], 'page', $page);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            Log::error("Failed to get data from table {$table}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve table data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync data from a specific table to the main database
     */
    public function syncTableData(Request $request, string $table): JsonResponse
    {
        try {
            // Validate table name
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid table name'
                ], 400);
            }

            DB::beginTransaction();
            
            // Get data from sync database
            $syncData = DB::connection('mysql_sync')
                ->table($table)
                ->get();
            
            $synced = 0;
            $failed = 0;
            $errors = [];

            foreach ($syncData as $row) {
                try {
                    // Insert or update in main database
                    DB::table($table)
                        ->updateOrInsert(
                            ['id' => $row->id ?? null],
                            (array)$row
                        );
                    $synced++;
                } catch (Exception $e) {
                    $failed++;
                    $errors[] = $e->getMessage();
                }
            }

            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "Synced data from table: {$table}",
                'synced' => $synced,
                'failed' => $failed,
                'errors' => $errors
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to sync table {$table}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync table data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Perform a full sync of all tables
     */
    public function fullSync(Request $request): JsonResponse
    {
        try {
            $tables = $request->input('tables', []);
            
            if (empty($tables)) {
                // Get all tables if none specified
                $tablesResult = DB::connection('mysql_sync')
                    ->select('SHOW TABLES');
                
                $tables = array_map(function($table) {
                    return array_values((array)$table)[0];
                }, $tablesResult);
            }

            $results = [];
            
            foreach ($tables as $table) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                    $results[$table] = [
                        'success' => false,
                        'message' => 'Invalid table name'
                    ];
                    continue;
                }

                try {
                    DB::beginTransaction();
                    
                    $syncData = DB::connection('mysql_sync')
                        ->table($table)
                        ->get();
                    
                    $synced = 0;
                    
                    foreach ($syncData as $row) {
                        DB::table($table)
                            ->updateOrInsert(
                                ['id' => $row->id ?? null],
                                (array)$row
                            );
                        $synced++;
                    }
                    
                    DB::commit();
                    
                    $results[$table] = [
                        'success' => true,
                        'synced' => $synced
                    ];
                } catch (Exception $e) {
                    DB::rollBack();
                    $results[$table] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Full sync completed',
                'results' => $results
            ]);
        } catch (Exception $e) {
            Log::error('Failed to perform full sync: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to perform full sync',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sync status information
     */
    public function getSyncStatus(): JsonResponse
    {
        try {
            $mainDbName = DB::connection()->getDatabaseName();
            $syncDbName = DB::connection('mysql_sync')->getDatabaseName();
            
            return response()->json([
                'success' => true,
                'status' => [
                    'main_database' => $mainDbName,
                    'sync_database' => $syncDbName,
                    'connection_active' => true,
                    'last_check' => now()->toDateTimeString()
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get sync status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get member data from sync database
     */
    public function getMemberData(Request $request): JsonResponse
    {
        try {
            $memberId = $request->query('member_id');
            
            if (!$memberId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member ID is required'
                ], 400);
            }

            $symbols = [
                'PHP' => '₱',
                'USD' => '$',
            ];

            // Query the sync database for member data
            $memberData = DB::connection('mysql_sync')
                ->table('masterlist')
                ->where('member_id', strtoupper($memberId))
                ->get([
                    'member_id as memberId',
                    DB::raw("CONCAT(first_name, ' ', IFNULL(CONCAT(middle_name, '. '), ''), last_name) as name"),
                    'company_name as company',
                    'rb',
                    'rbdep',
                    'ismbl',
                    'mbl',
                    'preexist',
                    'pemonth',
                    'philhealth',
                    'incepfrom',
                    'incepto',
                    'layer',
                    'currency',
                    'rb2',
                    'rb3',
                ])
                ->toArray();

            if (empty($memberData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No member data found'
                ], 404);
            }

            $result = [];
            foreach ($memberData as $data) {
                $data = (array) $data;

                switch ($data['preexist']) {
                    case 0:
                        $preexist = '--';
                        break;
                    case 1:
                        $preexist = 'Covered';
                        break;
                    case 2:
                        if (date('Y-m-d', strtotime("+" . $data['pemonth'] . " months", strtotime($data['incepfrom']))) <= date('Y-m-d')) {
                            $preexist = 'Covered';
                        } else {
                            $preexist = '--';
                        }
                        break;
                    default:
                        $preexist = '--';
                        break;
                }

                $rbdep = null;
                $rb = '--';

                if (isset($data['rb']) && is_numeric($data['rb'])) {
                    if ($data['rb'] > 0) {
                        $rb = '₱ ' . number_format($data['rb'], 2, '.', ',');
                    }
                } elseif (isset($data['rb']) && !empty($data['rb'])) {
                    $rb = $data['rb'];
                }

                if (!empty($data['rbdep'])) {
                    $rb = 'Principal: ' . $rb;
                    $rbdep = 'Dependent: ' . $data['rbdep'];
                }

                $company = ucwords(strtolower($data['company'] ?? ''));
                $company = preg_replace_callback('/\((.*?)\)/', function ($matches) {
                    return '(' . strtoupper($matches[1]) . ')';
                }, $company);

                $result[] = [
                    'memberId' => $data['memberId'] ?? '',
                    'name' => strtoupper($data['name'] ?? ''),
                    'company' => $company,
                    'roomAndBoard' => $rb,
                    'roomAndBoard2' => $data['rb2'] ?? '',
                    'roomAndBoard3' => $data['rb3'] ?? '',
                    'roomAndBoardDependent' => $rbdep ? ucwords(strtolower($rbdep)) : '',
                    'ismbl' => ($data['ismbl'] ?? 0) == 0 ? 'Maximum Benefit Limit' : 'Annual Benefit Limit',
                    'mbl' => (isset($data['mbl']) && $data['mbl'] > 0 && isset($symbols[$data['currency'] ?? 'PHP']))
                                ? $symbols[$data['currency'] ?? 'PHP'] . ' ' . number_format((float)$data['mbl'], 2, '.', ',')
                                : '',
                    'preExisting' => $preexist,
                    'philHealth' => ($data['philhealth'] ?? 0) ? 'Required' : 'Not Required',
                    'status' => ($data['incepto'] ?? '') < date('Y-m-d') ? 'Inactive' : 'Active',
                    'layer' => $data['layer'] ?? 0,
                    'dateOfInquiry' => date('d-M-Y h:i A')
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (Exception $e) {
            Log::error('Failed to get member data: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve member data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
