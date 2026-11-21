<?php

use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['password.changed' => RequirePasswordChange::class]);
        $middleware->appendToGroup('web', RequirePasswordChange::class);
        $middleware->validateCsrfTokens(except: ['oauth2/token']);
        $middleware->trimStrings(except: ['current_password', 'password', 'password_confirmation', 'new_password', 'new_password_confirmation']);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
