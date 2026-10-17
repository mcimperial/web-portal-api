<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\ImportEnrolleeController;

Route::post('import', [ImportEnrolleeController::class, 'import'])->middleware('rbac:imports,create');
Route::post('import-with-company-and-provider', [ImportEnrolleeController::class, 'importWithCompanyAndProvider'])->middleware('rbac:imports,create');
Route::get('import-logs', [ImportEnrolleeController::class, 'getImportLogs'])->middleware('rbac:imports,view');
Route::post('import-temp/compare', [ImportEnrolleeController::class, 'compareTempImportData'])->middleware('rbac:imports,view');
Route::get('import-logs/{id}/download', [ImportEnrolleeController::class, 'downloadImportLog'])->middleware('rbac:imports,export');
