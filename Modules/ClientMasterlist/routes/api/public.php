<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMasterlist\App\Http\Controllers\AttachmentController;
use Modules\ClientMasterlist\App\Http\Controllers\EnrolleeManageDependentController;

Route::apiResource('attachments', AttachmentController::class);

Route::controller(EnrolleeManageDependentController::class)->group(function () {
    Route::get('enrollee-manage-information/{uuid}', 'show');
    Route::put('enrollee-manage-information/{uuid}', 'update');
    Route::put('enrollee-manage-information/name/{uuid}', 'updateName');
    Route::put('enrollee-manage-information/gender/{uuid}', 'updateGender');
    Route::put('enrollee-manage-information/marital-status/{uuid}', 'updateMaritalStatus');
    Route::put('enrollee-manage-information/address/{uuid}', 'updateAddress');
    Route::put('enrollee-manage-information/update-on-renewal/{uuid}', 'updateOnRenewal');
    Route::post('enrollee-manage-dependents/{enrollee}/dependents/batch', 'storeBatch');
});
