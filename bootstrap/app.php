<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureSessionOwner;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RequireRole;
use App\Sso\Api\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'password.changed' => RequirePasswordChange::class,
            'account.active' => EnsureAccountActive::class,
            'session.owner' => EnsureSessionOwner::class,
            'role' => RequireRole::class,
        ]);
        // Whose session it is comes first, then what that account still has to do.
        $middleware->appendToGroup('web', [EnsureSessionOwner::class, RequirePasswordChange::class]);
        $middleware->validateCsrfTokens(except: ['oauth2/token', 'connect/logout']);
        $middleware->trimStrings(except: ['current_password', 'password', 'password_confirmation', 'new_password', 'new_password_confirmation']);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('userinfo')) {
                return response()->json(['error' => 'invalid_token'], 401, ['WWW-Authenticate' => 'Bearer']);
            }

            return null;
        });

        // Everything under /api answers in the envelope and never leaks exception detail.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof AuthenticationException) {
                return ApiResponse::error('UNAUTHORIZED', 'Sign in with an access token from this SSO server.', 401);
            }
            if ($e instanceof ValidationException) {
                return ApiResponse::error('VALIDATION_FAILED', 'The request is not valid.', 422, $e->errors());
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return match ($status) {
                403 => ApiResponse::error('FORBIDDEN', 'You are not allowed to do this.', 403),
                404 => ApiResponse::error('NOT_FOUND', 'Not found.', 404),
                405 => ApiResponse::error('METHOD_NOT_ALLOWED', 'This method is not allowed here.', 405),
                429 => ApiResponse::error('TOO_MANY_REQUESTS', 'Too many requests. Try again later.', 429),
                default => ApiResponse::error('INTERNAL_ERROR', 'Something went wrong.', 500),
            };
        });
    })->create();
