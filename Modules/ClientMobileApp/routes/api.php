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
    Route::post('login', [AuthController::class, 'login'])->name('login');
});

// Protected routes (authentication required)
Route::middleware(['auth:sanctum'])->prefix('v1/mobile')->name('api.mobile.')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout.all');
    Route::get('me', [AuthController::class, 'me'])->name('me');

    // Legacy route
    Route::get('clientmobileapp', fn(Request $request) => $request->user())->name('clientmobileapp');
});
