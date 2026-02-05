<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\ClientMobileApp\App\Http\Controllers\AuthController;

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
    
    // Account creation
    Route::post('register', [AuthController::class, 'createAccount'])->name('register');
    Route::post('create-account', [AuthController::class, 'createAccount'])->name('create-account');
    Route::post('check-email', [AuthController::class, 'checkEmail'])->name('check-email');
    
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
});

// Protected routes (authentication required)
Route::middleware(['auth:sanctum'])->prefix('v1/mobile')->name('api.mobile.')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout.all');
    Route::get('me', [AuthController::class, 'me'])->name('me');

    // Legacy route
    Route::get('clientmobileapp', fn(Request $request) => $request->user())->name('clientmobileapp');
});
