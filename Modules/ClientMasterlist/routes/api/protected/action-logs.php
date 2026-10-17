<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\ActionLogController;

Route::controller(ActionLogController::class)->group(function () {
    Route::get('action-logs/latest', 'getLatestForEnrollment')->middleware('rbac:action_logs,view');
    Route::get('action-logs/all', 'getAllForEnrollment')->middleware('rbac:action_logs,view');
});
