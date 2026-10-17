<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\DependentController;
use Modules\ClientMasterlist\App\Http\Controllers\EnrolleeController;
use Modules\ClientMasterlist\App\Http\Controllers\ExportEnrolleesController;

Route::get('enrollees/export', [ExportEnrolleesController::class, 'exportEnrollees'])->middleware('rbac:applications,export');
Route::get('enrollees/export-with-update', [ExportEnrolleesController::class, 'exportAndUpdateEnrollees'])->middleware('rbac:applications,export');
Route::get('enrollees/select', [EnrolleeController::class, 'getAllForSelect'])->middleware('rbac:applications,view');

Route::get('enrollees', [EnrolleeController::class, 'index'])->middleware('rbac:applications,view');
Route::post('enrollees', [EnrolleeController::class, 'store'])->middleware('rbac:applications,create');
Route::get('enrollees/{enrollee}', [EnrolleeController::class, 'show'])->middleware('rbac:applications,view');
Route::put('enrollees/{enrollee}', [EnrolleeController::class, 'update'])->middleware('rbac:applications,update');
Route::patch('enrollees/{enrollee}', [EnrolleeController::class, 'update'])->middleware('rbac:applications,update');
Route::delete('enrollees/{enrollee}', [EnrolleeController::class, 'destroy'])->middleware('rbac:applications,delete');
Route::put('enrollees/{id}/card-delivery', [EnrolleeController::class, 'updateCardDelivery'])->middleware('rbac:applications,update');

Route::apiResource('dependents', DependentController::class)->middleware('rbac:applications,update');
