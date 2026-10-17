<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\NotificationController;
use Modules\ClientMasterlist\App\Http\Controllers\SendNotificationController;

Route::controller(NotificationController::class)->group(function () {
    Route::get('notifications/enrollees', 'enrollees')->middleware('rbac:communications,view');
    Route::get('notifications/{enrolleeId}', 'index')->middleware('rbac:communications,view');
    Route::get('notifications/single/{enrolleeId}', 'single')->middleware('rbac:communications,view');
    Route::post('notifications', 'store')->middleware('rbac:communications,create');
    Route::put('notifications/{id}', 'update')->middleware('rbac:communications,update');
    Route::delete('notifications/{id}', 'destroy')->middleware('rbac:communications,delete');
});

Route::post('send-notification', [SendNotificationController::class, 'send'])->middleware('rbac:communications,create');
