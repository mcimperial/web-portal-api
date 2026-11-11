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
        Route::get('sync/test-connection', 'testConnection')->middleware('rbac:third_party_api,view');
        
        // Get all tables from sync database
        Route::get('sync/tables', 'getTables')->middleware('rbac:third_party_api,view');
        
        // Get data from a specific table
        Route::get('sync/tables/{table}', 'getTableData')->middleware('rbac:third_party_api,view');
        
        // Sync specific table data
        Route::post('sync/tables/{table}', 'syncTableData')->middleware('rbac:third_party_api,update');
        
        // Full sync operation
        Route::post('sync/full-sync', 'fullSync')->middleware('rbac:third_party_api,update');
        
        // Get sync status
        Route::get('sync/status', 'getSyncStatus')->middleware('rbac:third_party_api,view');
    });

    // -------------------------------------------------------------------------
    // API Credentials management (admin only, Sanctum-protected)
    // -------------------------------------------------------------------------
    Route::prefix('third-party/credentials')->name('credentials.')->controller(ApiCredentialController::class)->group(function () {
        Route::get('/',             'index')->middleware('rbac:third_party_api,view')->name('index');
        Route::post('/',            'store')->middleware('rbac:third_party_api,create')->name('store');
        Route::get('/{id}/usage-logs', 'usageLogs')->middleware('rbac:third_party_api,view')->name('usage-logs');
        Route::get('/{id}',         'show')->middleware('rbac:third_party_api,view')->name('show');
        Route::put('/{id}',         'update')->middleware('rbac:third_party_api,update')->name('update');
        Route::delete('/{id}',      'destroy')->middleware('rbac:third_party_api,delete')->name('destroy');
        Route::post('/{id}/regenerate', 'regenerateKey')->middleware('rbac:third_party_api,update')->name('regenerate');
    });
});

// -----------------------------------------------------------------------
// Third-party / external API endpoints — protected by X-API-Key header
// -----------------------------------------------------------------------
Route::prefix('v1/third-party')->name('third-party.')->group(function () {

    // Enrollment listing & detail (title-based)
    Route::get('enrollments',                                          [EnrollmentApiController::class, 'indexEnrollments'])->middleware('api.key:enrollment:read')->name('enrollments.index');
    Route::get('enrollments/{enrollmentTitle}/details',                [EnrollmentApiController::class, 'showEnrollment'])->middleware('api.key:enrollment:read')->name('enrollments.details');
    Route::get('enrollments/{enrollmentTitle}/summary',                [EnrollmentApiController::class, 'summary'])->middleware('api.key:enrollment:read')->name('enrollments.summary');

    // Principal endpoints
    Route::get('principals',                                            [EnrollmentApiController::class, 'listPrincipals'])->middleware('api.key:principal:list:read')->name('principals.index');
    Route::get('principals/read',                                       [EnrollmentApiController::class, 'showPrincipal'])->middleware('api.key:principal:read')->name('principals.read');

    // Dependent endpoints
    Route::get('enrollments/{enrollmentTitle}/principals/{principalEmployeeId}/dependents', [EnrollmentApiController::class, 'dependents'])->middleware('api.key:dependent:read')->name('dependents.index');
});
