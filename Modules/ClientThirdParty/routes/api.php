<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientThirdParty\App\Http\Controllers\SyncController;
use Modules\ClientThirdParty\App\Http\Controllers\ApiCredentialController;
use Modules\ClientThirdParty\App\Http\Controllers\EnrollmentApiController;

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

    // -------------------------------------------------------------------------
    // API Credentials management (admin only, Sanctum-protected)
    // -------------------------------------------------------------------------
    Route::prefix('third-party/credentials')->name('credentials.')->controller(ApiCredentialController::class)->group(function () {
        Route::get('/',             'index')->name('index');
        Route::post('/',            'store')->name('store');
        Route::get('/{id}',         'show')->name('show');
        Route::put('/{id}',         'update')->name('update');
        Route::delete('/{id}',      'destroy')->name('destroy');
        Route::post('/{id}/regenerate', 'regenerateKey')->name('regenerate');
    });
});

// -----------------------------------------------------------------------
// Third-party / external API endpoints — protected by X-API-Key header
// -----------------------------------------------------------------------
Route::middleware(['api.key:enrollment:read'])->prefix('v1/third-party')->name('third-party.')->group(function () {

    // Enrollment listing & detail
    Route::get('enrollments',                                          [EnrollmentApiController::class, 'indexEnrollments'])->name('enrollments.index');
    Route::get('enrollments/{id}',                                     [EnrollmentApiController::class, 'showEnrollment'])->name('enrollments.show');
    Route::get('enrollments/{id}/summary',                             [EnrollmentApiController::class, 'summary'])->name('enrollments.summary');
    Route::get('enrollments/{id}/principals',                          [EnrollmentApiController::class, 'principals'])->name('principals.index');
    Route::get('enrollments/{id}/principals/{principalId}/dependents', [EnrollmentApiController::class, 'dependents'])->name('dependents.index');

    // Principal search across all (or a single) enrollment
    Route::get('principals/search',                                    [EnrollmentApiController::class, 'searchPrincipals'])->name('principals.search');
});
