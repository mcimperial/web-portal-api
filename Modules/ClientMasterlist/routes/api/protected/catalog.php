<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\InsuranceProviderController;

Route::apiResource('insurance-providers', InsuranceProviderController::class)->middleware('rbac:settings,update');
