<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Modules\DonationDrive\App\Http\Controllers\AdminDonationDriveController;
use Modules\DonationDrive\App\Http\Controllers\DonorDonationDriveController;
use Modules\DonationDrive\App\Http\Controllers\DonorAdminManagementController;

Route::prefix('v1/donation-drive')->name('api.donation-drive.')->group(function () {
    Route::withoutMiddleware(EnsureFrontendRequestsAreStateful::class)->group(function () {
        Route::post('login/request-otp', [DonorDonationDriveController::class, 'requestOtp'])->name('login.request-otp');
        Route::post('login/verify-otp', [DonorDonationDriveController::class, 'verifyOtp'])->name('login.verify-otp');
        Route::get('documents/{document}/preview', [DonorDonationDriveController::class, 'preview'])
            ->middleware('signed')
            ->name('document.preview');

        Route::middleware('auth:donation')->group(function () {
            Route::post('logout', [DonorDonationDriveController::class, 'logout']);
            Route::get('drives', [DonorDonationDriveController::class, 'drives']);
            Route::post('drives/{drive}/donations', [DonorDonationDriveController::class, 'donate']);
            Route::get('my-donations', [DonorDonationDriveController::class, 'myDonations']);
            Route::prefix('donor-admin')->group(function () {
                Route::get('drives', [DonorAdminManagementController::class, 'index']);
                Route::post('drives', [DonorAdminManagementController::class, 'store']);
                Route::patch('drives/{drive}/status', [DonorAdminManagementController::class, 'status']);
                Route::patch('drives/{drive}/closure', [DonorAdminManagementController::class, 'closure']);
                Route::post('drives/{drive}/documents', [DonorAdminManagementController::class, 'uploadDocument']);
                Route::get('donors', [DonorAdminManagementController::class, 'donors']);
            });
            Route::get('donor-admin/reports/drives', [DonorDonationDriveController::class, 'reportDrives']);
            Route::get('donor-admin/reports/drives/{drive}', [DonorDonationDriveController::class, 'report']);
            Route::get('donor-admin/reports/drives/{drive}/export', [DonorDonationDriveController::class, 'exportReport']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::prefix('admin')->group(function () {
            Route::get('drives', [AdminDonationDriveController::class, 'index'])
                ->middleware('rbac:donation_drives,view');
            Route::post('drives', [AdminDonationDriveController::class, 'store'])
                ->middleware('rbac:donation_drives,create');
            Route::patch('drives/{drive}/status', [AdminDonationDriveController::class, 'status'])
                ->middleware('rbac:donation_drives,update');
            Route::patch('drives/{drive}/closure', [AdminDonationDriveController::class, 'closure'])
                ->middleware('rbac:donation_drives,update');
            Route::post('drives/{drive}/documents', [AdminDonationDriveController::class, 'uploadDocument'])
                ->middleware('rbac:donation_drives,update');
            Route::delete('documents/{document}', [AdminDonationDriveController::class, 'deleteDocument'])
                ->middleware('rbac:donation_drives,delete');
            Route::get('users', [AdminDonationDriveController::class, 'users'])
                ->middleware('rbac:donation_drives,update');
            Route::put('drives/{drive}/report-users', [AdminDonationDriveController::class, 'updateReportUsers'])
                ->middleware('rbac:donation_drives,update');
            Route::get('donors', [AdminDonationDriveController::class, 'donors'])
                ->middleware('rbac:donation_drives,view');
            Route::patch('donors/{donor}/admin', [AdminDonationDriveController::class, 'setDonorAdmin'])
                ->middleware('rbac:donation_drives,update');
            Route::delete('donors/{donor}', [AdminDonationDriveController::class, 'deleteDonor'])
                ->middleware('rbac:donation_drives,delete');
            Route::get('reports/drives', [AdminDonationDriveController::class, 'reportDrives'])
                ->middleware('rbac:reports,view');
            Route::get('reports/drives/{drive}', [AdminDonationDriveController::class, 'report'])
                ->middleware('rbac:reports,view');
            Route::get('reports/drives/{drive}/export', [AdminDonationDriveController::class, 'export'])
                ->middleware('rbac:reports,export');
        });
    });
});
