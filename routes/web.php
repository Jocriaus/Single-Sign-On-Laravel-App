<?php

use App\Sso\HealthProbe;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/actuator/health', function () {
    $up = app(HealthProbe::class)->databaseIsUp();

    return response()->json(['status' => $up ? 'UP' : 'DOWN'], $up ? 200 : 503);
});
