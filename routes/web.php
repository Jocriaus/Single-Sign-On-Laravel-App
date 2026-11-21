<?php

use App\Http\Controllers\Auth\HomeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\OAuth\DiscoveryController;
use App\Sso\HealthProbe;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->middleware('auth')->name('home');

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'attempt'])->name('login.attempt');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/change-password', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/change-password', [PasswordChangeController::class, 'update'])->name('password.update');
});

Route::get('/actuator/health', function () {
    $up = app(HealthProbe::class)->databaseIsUp();

    return response()->json(['status' => $up ? 'UP' : 'DOWN'], $up ? 200 : 503);
});

Route::get('/.well-known/openid-configuration', [DiscoveryController::class, 'configuration']);
Route::get('/oauth2/jwks', [DiscoveryController::class, 'jwks']);
