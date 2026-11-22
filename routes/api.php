<?php

use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\RoleController;
use Illuminate\Support\Facades\Route;

// Called with an access token issued by this server; never with a browser session.
Route::prefix('v1')->middleware(['auth:api', 'account.active'])->group(function () {
    Route::get('/user/me', MeController::class);

    // The admin routes also need the sso.admin scope and a current role, checked last.
    Route::middleware('sso.admin:ICTO_ADMIN,ADMIN')->prefix('admin/user')->controller(AdminUserController::class)->group(function () {
        Route::post('/', 'store');
        Route::get('/all', 'index');
        Route::get('/{id}', 'show');
        Route::put('/{id}', 'update');
        Route::put('/{id}/lock', 'lock');
        Route::put('/{id}/enable', 'enable');
        Route::put('/{id}/unlock', 'unlock');
        Route::put('/{id}/force-password-reset', 'forcePasswordReset');
    });

    Route::middleware('sso.admin:ICTO_ADMIN')->group(function () {
        Route::controller(ClientController::class)->prefix('client')->group(function () {
            Route::post('/', 'store');
            Route::get('/', 'index');
            Route::get('/paginated', 'paginated');
            Route::get('/{id}', 'show');
            Route::delete('/{id}', 'destroy');
        });

        Route::controller(RoleController::class)->prefix('admin/role')->group(function () {
            Route::post('/', 'store');
            Route::get('/all', 'index');
            Route::get('/paginated', 'paginated');
            Route::put('/{id}', 'update');
        });

        Route::get('/admin/logs', [LogController::class, 'index']);
    });
});
