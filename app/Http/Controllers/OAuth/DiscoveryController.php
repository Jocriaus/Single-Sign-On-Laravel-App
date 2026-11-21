<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Sso\OAuth\Scopes;
use App\Sso\OAuth\SigningKey;
use Illuminate\Http\JsonResponse;

class DiscoveryController extends Controller
{
    public function configuration(): JsonResponse
    {
        $iss = config('sso.issuer');

        return $this->cached([
            'issuer' => $iss,
            'authorization_endpoint' => "$iss/oauth2/authorize",
            'token_endpoint' => "$iss/oauth2/token",
            'userinfo_endpoint' => "$iss/userinfo",
            'jwks_uri' => "$iss/oauth2/jwks",
            'end_session_endpoint' => "$iss/connect/logout",
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => Scopes::ALLOWED,
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'backchannel_logout_supported' => true,
            'backchannel_logout_session_supported' => true,
        ]);
    }

    public function jwks(SigningKey $key): JsonResponse
    {
        return $this->cached(['keys' => [$key->jwk()]]);
    }

    private function cached(array $body): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', 'public, max-age=3600');
    }
}
