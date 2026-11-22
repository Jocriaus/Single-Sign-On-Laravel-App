<?php

namespace App\Http\Middleware;

use App\Models\ApiAuditLog;
use App\Sso\Api\SensitiveDataMasker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Writes one audit row per API call, after the answer exists, with secrets masked. */
class AuditApi
{
    public function __construct(private SensitiveDataMasker $masker) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api', 'api/*')) {
            return $next($request);
        }

        $response = $next($request);

        // The audit trail must never break the call it describes.
        try {
            $this->record($request, $response);
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $content = $response->getContent();
        $envelope = is_string($content) ? json_decode($content, true) : null;
        $envelope = is_array($envelope) ? $envelope : [];
        $status = $response->getStatusCode();
        [$userId, $clientId] = $this->caller($request);

        ApiAuditLog::create([
            'user_id' => $userId,
            'client_id' => $clientId,
            'endpoint' => mb_substr($request->getPathInfo(), 0, 255),
            'http_method' => $request->getMethod(),
            'ip_address' => $request->ip(),
            'user_agent' => ($agent = $request->userAgent()) === null ? null : mb_substr($agent, 0, 255),
            'status_code' => $status,
            'status' => $status < 400 ? 'success' : 'error',
            'error_code' => isset($envelope['errorCode']) ? mb_substr((string) $envelope['errorCode'], 0, 50) : null,
            'message' => isset($envelope['message']) && is_string($envelope['message']) ? $envelope['message'] : null,
            'request_body' => $this->requestBody($request),
            'response_body' => is_string($content) ? $this->masker->mask($content) : null,
        ]);
    }

    /** @return array{0: int|string|null, 1: string|null} */
    private function caller(Request $request): array
    {
        try {
            $user = $request->user('api');

            return [$user?->getKey(), $user?->token()?->client_id];
        } catch (Throwable) {
            return [null, null];
        }
    }

    private function requestBody(Request $request): ?string
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return null;
        }

        $input = $request->isJson() ? $request->json()->all() : $request->post();

        return $input === [] ? null : $this->masker->mask(json_encode($input, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
