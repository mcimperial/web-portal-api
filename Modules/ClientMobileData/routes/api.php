<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientMobileData\App\Http\Controllers\ClaimsController;
use Modules\ClientMobileData\App\Http\Controllers\ComplaintController;
use Modules\ClientMobileData\App\Http\Controllers\DoctorController;
use Modules\ClientMobileData\App\Http\Controllers\HealthController;
use Modules\ClientMobileData\App\Http\Controllers\HospitalController;
use Modules\ClientMobileData\App\Http\Controllers\LoaRequestController;
use Modules\ClientMobileData\App\Http\Controllers\MemberController;

/*
    |--------------------------------------------------------------------------
    | API Routes — ClientMobileData
    |--------------------------------------------------------------------------
    |
    | Mobile app data endpoints backed by llibiapp_sync (members,
    | hospitals, doctors) and llibiapp_portal (LOA requests, claims).
    |
*/

// Public health check for both database connections.
Route::prefix('v1/mobile')->name('api.mobile.data.')->group(function () {
    Route::get('health', [HealthController::class, 'index'])->name('health');
});

// Protected data endpoints.
Route::middleware(['auth:mobile', 'mobile.user'])->prefix('v1/mobile')->name('api.mobile.data.')->group(function () {
    // Member profile & dependents (llibiapp_sync.masterlist)
    Route::get('member/profile', [MemberController::class, 'profile'])->name('member.profile');
    Route::get('member/dependents', [MemberController::class, 'dependents'])->name('member.dependents');
    Route::get('complaints', [ComplaintController::class, 'index'])->name('complaints.index');
    Route::get('complaints/custom', [ComplaintController::class, 'customIndex'])->name('complaints.custom.index');
    Route::post('complaints/custom', [ComplaintController::class, 'store'])->name('complaints.custom.store');

    // Hospitals / find-hospital (llibiapp_sync.hospitals)
    Route::get('hospitals', [HospitalController::class, 'index'])->name('hospitals.index');
    Route::get('hospitals/{id}', [HospitalController::class, 'show'])->whereNumber('id')->name('hospitals.show');
    Route::get('hospitals/{id}/doctors', [HospitalController::class, 'doctors'])->whereNumber('id')->name('hospitals.doctors');

    // Doctors search (llibiapp_sync.doctors + doctors_clinics)
    Route::get('doctors', [DoctorController::class, 'index'])->name('doctors.index');
    Route::get('doctors/{id}', [DoctorController::class, 'show'])->whereNumber('id')->name('doctors.show');

    // LOA / HMO requests (llibiapp_portal.app_portal_requests)
    Route::get('loa/requests', [LoaRequestController::class, 'index'])->name('loa.index');
    Route::post('loa/requests', [LoaRequestController::class, 'store'])->name('loa.store');
    Route::get('loa/requests/{id}', [LoaRequestController::class, 'show'])->whereNumber('id')->name('loa.show');

    // Remaining benefits (llibiapp_portal.app_remaining)
    Route::get('claims/remaining', [ClaimsController::class, 'remaining'])->name('claims.remaining');
});
