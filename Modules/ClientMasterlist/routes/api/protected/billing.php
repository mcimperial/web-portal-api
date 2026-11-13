<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\BillingController;

Route::get('billing', [BillingController::class, 'index'])->middleware('rbac:enrollments,view');
Route::post('billing/import', [BillingController::class, 'store'])->middleware('rbac:imports,create');
Route::post('billing/recon-report-2/soft-delete', [BillingController::class, 'softDeleteReconReport2Row'])->middleware('rbac:applications,delete');