<?php

use Illuminate\Support\Facades\Route;

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

$protectedRouteFiles = [
    __DIR__.'/api/protected/imports.php',
    __DIR__.'/api/protected/catalog.php',
    __DIR__.'/api/protected/enrollments.php',
    __DIR__.'/api/protected/billing.php',
    __DIR__.'/api/protected/enrollees.php',
    __DIR__.'/api/protected/communications.php',
    __DIR__.'/api/protected/action-logs.php',
];

Route::middleware(['auth:sanctum'])
    ->prefix('v1')
    ->name('api.')
    ->group(function () use ($protectedRouteFiles) {
        foreach ($protectedRouteFiles as $routeFile) {
            require $routeFile;
        }
    });

Route::prefix('v1')
    ->name('api.')
    ->group(__DIR__.'/api/public.php');
