<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\InsuranceProviderController;
use Modules\ClientMasterlist\App\Http\Controllers\EnrollmentController;
use Modules\ClientMasterlist\App\Http\Controllers\EnrolleeController;
use Modules\ClientMasterlist\App\Http\Controllers\EnrolleeManageDependentController;

use Modules\ClientMasterlist\App\Http\Controllers\DependentController;
use Modules\ClientMasterlist\App\Http\Controllers\AttachmentController;
use Modules\ClientMasterlist\App\Http\Controllers\ImportEnrolleeController;
use Modules\ClientMasterlist\App\Http\Controllers\SendNotificationController;
use Modules\ClientMasterlist\App\Http\Controllers\NotificationController;

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

// Protected routes (auth:sanctum)
Route::middleware(['auth:sanctum'])->prefix('v1')->name('api.')->group(function () {
    //Route::get('clientmasterlist', fn(Request $request) => $request->user())->name('clientmasterlist');

    // Insurance Providers CRUD
    Route::apiResource('insurance-providers', InsuranceProviderController::class);

    // Enrollment CRUD
    Route::apiResource('enrollments', EnrollmentController::class);

    // Enrollee CRUD
    Route::apiResource('enrollees', EnrolleeController::class);

    // Dependents CRUD
    Route::apiResource('dependents', DependentController::class);

    // Import enrollees (with dependents and health insurance)
    Route::post('enrollees/import', [ImportEnrolleeController::class, 'import']);

    // Notification CRUD API
    Route::controller(NotificationController::class)->group(function () {
        Route::get('notifications/enrollees', 'enrollees');
        Route::get('notifications/{enrolleeId}', 'index');
        Route::get('notifications/single/{enrolleeId}', 'single');
        Route::post('notifications', 'store');
        Route::put('notifications/{id}', 'update');
    });

    // Send Notification API
    Route::post('send-notification', [SendNotificationController::class, 'send']);
});

// Public Attachments CRUD (no auth)
Route::prefix('v1')->name('api.')->group(function () {
    Route::apiResource('attachments', AttachmentController::class)->only(['index', 'store', 'destroy']);

    // Enrollee by UUID
    Route::get('principal-enrollees/uuid/{uuid}', [\Modules\ClientMasterlist\App\Http\Controllers\EnrolleeUuidController::class, 'show']);
    Route::put('principal-enrollees/uuid/{uuid}', [\Modules\ClientMasterlist\App\Http\Controllers\EnrolleeUuidController::class, 'update']);

    // Nested: Create dependent for enrollee
    Route::post('principal-enrollees/{enrollee}/dependents', [EnrolleeManageDependentController::class, 'store']);

    // Batch create dependents for enrollee
    Route::post('principal-enrollees/{enrollee}/dependents/batch', [EnrolleeManageDependentController::class, 'storeBatch']);
});
