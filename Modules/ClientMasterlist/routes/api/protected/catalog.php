<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\InsuranceProviderController;

Route::get('insurance-providers', [InsuranceProviderController::class, 'index'])->middleware('rbac:enrollments,view');
Route::get('insurance-providers/{insurance_provider}', [InsuranceProviderController::class, 'show'])->middleware('rbac:enrollments,view');
Route::post('insurance-providers', [InsuranceProviderController::class, 'store'])->middleware('rbac:settings,update');
Route::patch('insurance-providers/{insurance_provider}', [InsuranceProviderController::class, 'update'])->middleware('rbac:settings,update');
Route::delete('insurance-providers/{insurance_provider}', [InsuranceProviderController::class, 'destroy'])->middleware('rbac:settings,update');
