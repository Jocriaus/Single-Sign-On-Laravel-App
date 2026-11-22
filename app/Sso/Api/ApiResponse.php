<?php

namespace App\Sso\Api;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** The one JSON shape every /api answer uses. */
final class ApiResponse
{
    public static function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return self::build(true, $message, $data, $status, null);
    }

    public static function error(string $errorCode, string $message, int $status = 400, mixed $data = null): JsonResponse
    {
        return self::build(false, $message, $data, $status, $errorCode);
    }

    private static function build(bool $success, string $message, mixed $data, int $status, ?string $errorCode): JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
            'status' => $status,
            'errorCode' => $errorCode,
            'error' => $success ? null : (Response::$statusTexts[$status] ?? null),
        ], $status);
    }
}
