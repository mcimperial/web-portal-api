<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\BillingController;

Route::get('billing', [BillingController::class, 'index'])->middleware('rbac:enrollments,view');
Route::post('billing/import', [BillingController::class, 'store'])->middleware('rbac:imports,create');