<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientThirdParty\App\Http\Controllers\SyncController;

/*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    |
    | Here is where you can register API routes for your application. These
    | routes are loaded by the RouteServiceProvider within a group which
    | is assigned the "api" middleware group. Enjoy building your API!
    |
*/

// Public route for member validation (no authentication required)
Route::prefix('v1')->name('api.')->group(function () {
    // Public member validation endpoint
    Route::get('sync/member', [SyncController::class, 'getMemberData']);
});

// Protected routes (auth:sanctum)
Route::middleware(['auth:sanctum'])->prefix('v1')->name('api.')->group(function () {
    
    // Sync operations
    Route::controller(SyncController::class)->group(function () {
        // Test sync database connection
        Route::get('sync/test-connection', 'testConnection');
        
        // Get all tables from sync database
        Route::get('sync/tables', 'getTables');
        
        // Get data from a specific table
        Route::get('sync/tables/{table}', 'getTableData');
        
        // Sync specific table data
        Route::post('sync/tables/{table}', 'syncTableData');
        
        // Full sync operation
        Route::post('sync/full-sync', 'fullSync');
        
        // Get sync status
        Route::get('sync/status', 'getSyncStatus');
    });
});
