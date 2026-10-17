<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\EnrollmentController;

Route::controller(EnrollmentController::class)->group(function () {
    Route::get('enrollments/get-users', 'getUsers')->middleware('rbac:users,view');
    Route::get('enrollments/get-enrollment-roles', 'getEnrollmentRoles')->middleware('rbac:enrollments,view');
    Route::post('enrollments/assign-user', 'assignUserToEnrollment')->middleware('rbac:enrollments,update');
    Route::delete('enrollments/remove-user/{id}', 'removeUserFromEnrollment')->middleware('rbac:enrollments,update');
    Route::get('enrollments/{id}/access', 'getEnrollmentAccess')->middleware('rbac:enrollments,view');
});

Route::get('enrollments', [EnrollmentController::class, 'index'])->middleware('rbac:enrollments,view');
Route::post('enrollments', [EnrollmentController::class, 'store'])->middleware('rbac:enrollments,create');
Route::get('enrollments/{enrollment}', [EnrollmentController::class, 'show'])->middleware('rbac:enrollments,view');
Route::put('enrollments/{enrollment}', [EnrollmentController::class, 'update'])->middleware('rbac:enrollments,update');
Route::patch('enrollments/{enrollment}', [EnrollmentController::class, 'update'])->middleware('rbac:enrollments,update');
Route::delete('enrollments/{enrollment}', [EnrollmentController::class, 'destroy'])->middleware('rbac:enrollments,delete');
