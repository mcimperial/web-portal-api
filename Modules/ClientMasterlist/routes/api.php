<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\InsuranceProviderController;
use Modules\ClientMasterlist\App\Http\Controllers\EnrollmentController;
use Modules\ClientMasterlist\App\Http\Controllers\EnrolleeController;
use Modules\ClientMasterlist\App\Http\Controllers\EnrolleeManageDependentController;

use Modules\ClientMasterlist\App\Http\Controllers\DependentController;
use Modules\ClientMasterlist\App\Http\Controllers\AttachmentController;
use Modules\ClientMasterlist\App\Http\Controllers\ExportEnrolleesController;
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

    // Import enrollees (with dependents and health insurance)
    Route::post('import', [ImportEnrolleeController::class, 'import']);

    // Import enrollees (with dependents and health insurance)
    Route::post('import-with-company-and-provider', [ImportEnrolleeController::class, 'importWithCompanyAndProvider']);

    // Insurance Providers CRUD
    Route::apiResource('insurance-providers', InsuranceProviderController::class);

    Route::controller(EnrollmentController::class)->group(function () {
        Route::get('enrollments/get-users', 'getUsers');
        Route::get('enrollments/get-enrollment-roles', 'getEnrollmentRoles');
        Route::post('enrollments/assign-user', 'assignUserToEnrollment');
        Route::delete('enrollments/remove-user/{id}', 'removeUserFromEnrollment');
    });

    // Enrollment CRUD
    Route::apiResource('enrollments', EnrollmentController::class);

    // Export enrollees as CSV (must be above apiResource to avoid shadowing)
    Route::get('enrollees/export', [ExportEnrolleesController::class, 'exportEnrollees']);

    Route::get('/enrollees/select', [EnrolleeController::class, 'getAllForSelect']);

    // Enrollee CRUD
    Route::apiResource('enrollees', EnrolleeController::class);

    // Dependents CRUD
    Route::apiResource('dependents', DependentController::class);

    // Notification CRUD API
    Route::controller(NotificationController::class)->group(function () {
        Route::get('notifications/enrollees', 'enrollees');
        Route::get('notifications/{enrolleeId}', 'index');
        Route::get('notifications/single/{enrolleeId}', 'single');
        Route::post('notifications', 'store');
        Route::put('notifications/{id}', 'update');
        Route::delete('notifications/{id}', 'destroy');
    });

    // Send Notification API
    Route::post('send-notification', [SendNotificationController::class, 'send']);

    // Test endpoint for approved enrollees (for debugging APPROVED BY HMO functionality)
    Route::post('test-approved-enrollees', [SendNotificationController::class, 'testApprovedEnrollees']);

    // Test endpoint for CSV generation (for debugging CSV attachment functionality)
    Route::post('test-csv-generation', [SendNotificationController::class, 'testCsvGeneration']);

    // Test endpoint for date range calculation (for debugging schedule-based date filtering)
    Route::post('test-date-range-calculation', [SendNotificationController::class, 'testDateRangeCalculation']);

    // Test endpoint for status update (for debugging SUBMITTED to FOR-APPROVAL status change)
    Route::post('test-status-update', [ExportEnrolleesController::class, 'testStatusUpdate']);
});

// Public Attachments CRUD (no auth)
Route::prefix('v1')->name('api.')->group(function () {
    Route::apiResource('attachments', AttachmentController::class);

    Route::controller(EnrolleeManageDependentController::class)->group(function () {
        Route::get('enrollee-manage-information/{uuid}', 'show');
        Route::put('enrollee-manage-information/{uuid}', 'update');
        Route::put('enrollee-manage-information/gender-and-marital-status/{uuid}', 'updateGenderAndMaritalStatus');
        Route::put('enrollee-manage-information/update-on-renewal/{uuid}', 'updateOnRenewal');
        Route::post('enrollee-manage-dependents/{enrollee}/dependents/batch', 'storeBatch');
    });
});
