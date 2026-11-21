<?php

use App\Http\Controllers\Auth\HomeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\OAuth\AuthorizeController;
use App\Http\Controllers\OAuth\DiscoveryController;
use App\Sso\HealthProbe;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;

Route::get('/', HomeController::class)->middleware('auth')->name('home');

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'attempt'])->name('login.attempt');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/change-password', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/change-password', [PasswordChangeController::class, 'update'])->name('password.update');
    // RequirePasswordChange (web group) also guards this route.
    Route::get('/oauth2/authorize', AuthorizeController::class);
});

Route::get('/actuator/health', function () {
    $up = app(HealthProbe::class)->databaseIsUp();

    return response()->json(['status' => $up ? 'UP' : 'DOWN'], $up ? 200 : 503);
});

Route::withoutMiddleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    \App\Http\Middleware\RequirePasswordChange::class,
])->group(function () {
    Route::get('/.well-known/openid-configuration', [DiscoveryController::class, 'configuration']);
    Route::get('/oauth2/jwks', [DiscoveryController::class, 'jwks']);
    // Called by apps server to server.
    Route::post('/oauth2/token', [AccessTokenController::class, 'issueToken']);
});
