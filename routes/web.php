<?php

use App\Http\Controllers\Auth\HomeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Console\AppsController;
use App\Http\Controllers\Console\OverviewController;
use App\Http\Controllers\Console\UsersController;
use App\Http\Controllers\OAuth\AuthorizeController;
use App\Http\Controllers\OAuth\DiscoveryController;
use App\Http\Controllers\OAuth\EndSessionController;
use App\Http\Controllers\OAuth\UserInfoController;
use App\Sso\HealthProbe;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;

Route::get('/', HomeController::class)->middleware('auth')->name('home');

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'attempt'])->name('login.attempt');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
// Visited by the browser, sent by an app: needs the session; apps post to it cross-site, so no CSRF token.
Route::match(['get', 'post'], '/connect/logout', EndSessionController::class);

Route::middleware('auth')->group(function () {
    Route::get('/change-password', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/change-password', [PasswordChangeController::class, 'update'])->name('password.update');
    // RequirePasswordChange (web group) also guards this route.
    Route::get('/oauth2/authorize', AuthorizeController::class);
});

Route::prefix('admin')->middleware(['auth', 'role:ICTO_ADMIN,ADMIN'])->group(function () {
    Route::get('/', OverviewController::class)->name('admin.overview');

    Route::prefix('users')->controller(UsersController::class)->group(function () {
        Route::get('/', 'index')->name('admin.users');
        Route::get('/new', 'create')->name('admin.users.new');
        Route::post('/', 'store')->name('admin.users.store');
        Route::get('/{user}', 'show')->name('admin.users.show');
        Route::post('/{user}', 'update')->name('admin.users.update');
        Route::post('/{user}/unlock', 'unlock')->name('admin.users.unlock');
        Route::post('/{user}/require-password-change', 'requirePasswordChange')->name('admin.users.require-password-change');
        Route::post('/{user}/reset-password', 'resetPassword')->name('admin.users.reset-password');
        Route::post('/{user}/disable', 'disable')->name('admin.users.disable');
        Route::post('/{user}/enable', 'enable')->name('admin.users.enable');
        Route::post('/{user}/roles', 'roles')->name('admin.users.roles');
    });

    Route::middleware('role:ICTO_ADMIN')->prefix('apps')->controller(AppsController::class)->group(function () {
        Route::get('/', 'index')->name('admin.apps');
        Route::get('/new', 'create')->name('admin.apps.new');
        Route::post('/', 'store')->name('admin.apps.store');
        Route::get('/{client}', 'show')->name('admin.apps.show');
        Route::post('/{client}', 'update')->name('admin.apps.update');
        Route::post('/{client}/secret', 'resetSecret')->name('admin.apps.secret');
        Route::post('/{client}/delete', 'destroy')->name('admin.apps.delete');
    });
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
    \App\Http\Middleware\EnsureSessionOwner::class,
])->group(function () {
    Route::get('/.well-known/openid-configuration', [DiscoveryController::class, 'configuration']);
    Route::get('/oauth2/jwks', [DiscoveryController::class, 'jwks']);
    // Called by apps server to server.
    Route::post('/oauth2/token', [AccessTokenController::class, 'issueToken']);
    // Called by apps with a bearer token.
    Route::match(['get', 'post'], '/userinfo', UserInfoController::class)->middleware(['auth:api', 'account.active']);
});
