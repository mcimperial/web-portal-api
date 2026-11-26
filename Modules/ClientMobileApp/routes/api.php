<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\ClientMobileApp\App\Http\Controllers\AuthController;
use Modules\ClientMobileApp\App\Http\Controllers\CallbackRequestController;
use Modules\ClientMobileApp\App\Http\Controllers\ClaimsController;
use Modules\ClientMobileApp\App\Http\Controllers\ComplaintController;
use Modules\ClientMobileApp\App\Http\Controllers\DoctorController;
use Modules\ClientMobileApp\App\Http\Controllers\HealthController;
use Modules\ClientMobileApp\App\Http\Controllers\HospitalController;
use Modules\ClientMobileApp\App\Http\Controllers\LegalDocumentController;
use Modules\ClientMobileApp\App\Http\Controllers\LoaRequestController;
use Modules\ClientMobileApp\App\Http\Controllers\MemberController;
use Modules\ClientMobileApp\App\Http\Controllers\MobileAdminController;
use Modules\ClientMobileApp\App\Http\Controllers\PopupController;

/*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    |
    | Here is where you can register API routes for your application. These
    | routes are loaded by the RouteServiceProvider within a group which
    | is assigned the "api" middleware group. Enjoy building your API!
    |
*/

// Public routes (no authentication required)
Route::prefix('v1/mobile')->name('api.mobile.')->group(function () {
    // Traditional login
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password')->middleware('throttle:5,1');
    Route::post('forgot-password/verify', [AuthController::class, 'verifyPasswordResetOtp'])->name('forgot-password.verify')->middleware('throttle:10,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password')->middleware('throttle:10,1');

    // Account creation
    Route::post('register', [AuthController::class, 'createAccount'])->name('register');
    Route::post('create-account', [AuthController::class, 'createAccount'])->name('create-account');
    Route::post('check-email', [AuthController::class, 'checkEmail'])->name('check-email');

    // Member activation
    Route::post('activate', [AuthController::class, 'activateMember'])->name('activate');

    // Email OTP verification
    Route::post('send-otp', [AuthController::class, 'sendOtp'])->name('send-otp');
    Route::post('verify-otp', [AuthController::class, 'verifyOtp'])->name('verify-otp');
    Route::post('resend-otp', [AuthController::class, 'resendOtp'])->name('resend-otp');

    // Email OTP verification (register prefix)
    Route::post('register/send-otp', [AuthController::class, 'sendOtp'])->name('register.send-otp');
    Route::post('register/verify-otp', [AuthController::class, 'verifyOtp'])->name('register.verify-otp');
    Route::post('register/resend-otp', [AuthController::class, 'resendOtp'])->name('register.resend-otp');

    // Social media authentication
    Route::post('social-login', [AuthController::class, 'socialLogin'])->name('social-login');
    Route::post('google-signin', [AuthController::class, 'googleSignIn'])->name('google-signin');
    Route::post('facebook-signin', [AuthController::class, 'facebookSignIn'])->name('facebook-signin');
    Route::post('apple-signin', [AuthController::class, 'appleSignIn'])->name('apple-signin');

    // Startup popups shown after the splash screen
    Route::get('popups', [PopupController::class, 'index'])->name('popups.index');
    Route::get('legal-documents', [LegalDocumentController::class, 'index'])->name('legal-documents.index');
});

// Protected routes (authentication required)
Route::middleware(['auth:mobile', 'mobile.user'])->prefix('v1/mobile')->name('api.mobile.')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout.all');
    Route::put('account', [AuthController::class, 'updateAccount'])->name('account.update');
    Route::put('account/password', [AuthController::class, 'changePassword'])->name('account.password');
    Route::delete('account', [AuthController::class, 'deleteAccount'])->name('account.delete');
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::get('callback-requests', [CallbackRequestController::class, 'index'])->name('callback-requests.index');
    Route::post('callback-requests', [CallbackRequestController::class, 'store'])->name('callback-requests.store');
    Route::post('member/link', [MemberController::class, 'link'])->name('member.link');

    // Legacy route
    Route::get('clientmobileapp', fn (Request $request) => $request->user())->name('clientmobileapp');
});

Route::prefix('v1/mobile')->name('api.mobile.data.')->group(function () {
    Route::get('health', [HealthController::class, 'index'])->name('health');
});

Route::middleware(['auth:mobile', 'mobile.user'])->prefix('v1/mobile')->name('api.mobile.data.')->group(function () {
    Route::get('member/profile', [MemberController::class, 'profile'])->name('member.profile');
    Route::get('member/dependents', [MemberController::class, 'dependents'])->name('member.dependents');
    Route::get('complaints', [ComplaintController::class, 'index'])->name('complaints.index');
    Route::get('complaints/custom', [ComplaintController::class, 'customIndex'])->name('complaints.custom.index');
    Route::post('complaints/custom', [ComplaintController::class, 'store'])->name('complaints.custom.store');

    Route::get('hospitals', [HospitalController::class, 'index'])->name('hospitals.index');
    Route::get('hospitals/{id}', [HospitalController::class, 'show'])->whereNumber('id')->name('hospitals.show');
    Route::get('hospitals/{id}/doctors', [HospitalController::class, 'doctors'])->whereNumber('id')->name('hospitals.doctors');

    Route::get('doctors', [DoctorController::class, 'index'])->name('doctors.index');
    Route::get('doctors/{id}', [DoctorController::class, 'show'])->whereNumber('id')->name('doctors.show');

    Route::get('loa/requests', [LoaRequestController::class, 'index'])->name('loa.index');
    Route::post('loa/requests', [LoaRequestController::class, 'store'])->name('loa.store');
    Route::get('loa/requests/{id}', [LoaRequestController::class, 'show'])->whereNumber('id')->name('loa.show');

    Route::get('claims/remaining', [ClaimsController::class, 'remaining'])->name('claims.remaining');
});

Route::middleware('auth:sanctum')->prefix('v1/mobile-admin')->group(function () {
    Route::get('popups', [MobileAdminController::class, 'popups']);
    Route::post('popups', [MobileAdminController::class, 'storePopup']);
    Route::put('popups/{popup}', [MobileAdminController::class, 'updatePopup']);
    Route::delete('popups/{popup}', [MobileAdminController::class, 'deletePopup']);
    Route::get('legal-documents', [MobileAdminController::class, 'legalDocuments']);
    Route::put('legal-documents/{documentKey}', [MobileAdminController::class, 'updateLegalDocument'])
        ->whereIn('documentKey', ['terms', 'privacy']);
    Route::get('configuration', [MobileAdminController::class, 'configuration']);
    Route::put('configuration', [MobileAdminController::class, 'updateConfiguration']);
    Route::get('users', [MobileAdminController::class, 'users']);
    Route::post('users/revoke-activations', [MobileAdminController::class, 'revokeUserActivations']);
    Route::put('users/{mobileUser}', [MobileAdminController::class, 'updateUser']);
    Route::post('users/{mobileUser}/revoke', [MobileAdminController::class, 'revokeUser']);
    Route::delete('users/{mobileUser}', [MobileAdminController::class, 'deleteUser']);
    Route::get('apis', [MobileAdminController::class, 'apis']);
});
