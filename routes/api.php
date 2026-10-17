<?php

use Illuminate\Support\Facades\Route;

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

use App\Http\Controllers\UserController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\AccessManagementController;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', [UserController::class, 'current']);
    Route::get('company', [CompanyController::class, 'index'])->middleware('rbac:enrollments,view');
    Route::get('company/{company}', [CompanyController::class, 'show'])->middleware('rbac:enrollments,view');
    Route::post('company', [CompanyController::class, 'store'])->middleware('rbac:settings,update');
    Route::patch('company/{company}', [CompanyController::class, 'update'])->middleware('rbac:settings,update');
    Route::delete('company/{company}', [CompanyController::class, 'destroy'])->middleware('rbac:settings,update');

    Route::prefix('v1/access')->group(function () {
        Route::get('permissions/catalog', [AccessManagementController::class, 'permissionCatalog'])
            ->middleware('rbac:roles,view');

        Route::get('roles', [AccessManagementController::class, 'listRoles'])
            ->middleware('rbac:roles,view');
        Route::post('roles', [AccessManagementController::class, 'createRole'])
            ->middleware('rbac:roles,create');
        Route::put('roles/{role}', [AccessManagementController::class, 'updateRole'])
            ->middleware('rbac:roles,update');
        Route::delete('roles/{role}', [AccessManagementController::class, 'deleteRole'])
            ->middleware('rbac:roles,delete');
        Route::post('roles/{role}/duplicate', [AccessManagementController::class, 'duplicateRole'])
            ->middleware('rbac:roles,create');

        Route::get('users', [AccessManagementController::class, 'listUsers'])
            ->middleware('rbac:users,view');
        Route::get('users/{user}', [AccessManagementController::class, 'showUser'])
            ->middleware('rbac:users,view');
        Route::post('users', [AccessManagementController::class, 'createUser'])
            ->middleware('rbac:users,create');
        Route::put('users/{user}', [AccessManagementController::class, 'updateUser'])
            ->middleware('rbac:users,update');
        Route::post('users/{user}/invite', [AccessManagementController::class, 'resendUserInvite'])
            ->middleware('rbac:users,update');
        Route::patch('users/{user}/status', [AccessManagementController::class, 'setUserStatus'])
            ->middleware('rbac:users,update');
        Route::delete('users/{user}', [AccessManagementController::class, 'archiveUser'])
            ->middleware('rbac:users,delete');
        Route::delete('users/{user}/permanent', [AccessManagementController::class, 'permanentlyDeleteUser'])
            ->middleware('rbac:users,delete');

        Route::post('users/bulk/roles', [AccessManagementController::class, 'bulkAssignRoles'])
            ->middleware('rbac:users,update');
        Route::post('users/bulk/enrollments', [AccessManagementController::class, 'bulkAssignEnrollments'])
            ->middleware('rbac:users,update');

        Route::get('enrollments/options', [AccessManagementController::class, 'listEnrollmentsForAssignment'])
            ->middleware('rbac:enrollments,view');
    });
});
