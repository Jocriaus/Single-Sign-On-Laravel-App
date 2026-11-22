<?php

use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

// Called with an access token issued by this server; never with a browser session.
Route::prefix('v1')->middleware(['auth:api', 'account.active'])->group(function () {
    Route::get('/user/me', MeController::class);
});
