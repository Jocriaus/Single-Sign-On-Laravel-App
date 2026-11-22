<?php

namespace App\Providers;

use App\Models\Client;
use App\Sso\OAuth\AccessToken;
use App\Sso\OAuth\AccessTokenRepository;
use App\Sso\OAuth\AuthCodeRepository;
use App\Sso\OAuth\IdTokenResponse;
use App\Sso\OAuth\Scopes;
use App\Sso\OAuth\SignInContext;
use App\Sso\OAuth\SigningKey;
use Carbon\CarbonInterval;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\SecuritySchemes\OAuthFlow;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Passport::ignoreRoutes();
        $this->app->singleton(SigningKey::class);
        $this->app->singleton(SignInContext::class);

        // Passport builds its grants and authorization server from these container bindings.
        $this->app->bind(Bridge\AccessTokenRepository::class, AccessTokenRepository::class);
        $this->app->bind(Bridge\AuthCodeRepository::class, AuthCodeRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::useClientModel(Client::class);
        Passport::useAccessTokenEntity(AccessToken::class);
        Passport::useAuthorizationServerResponseType(new IdTokenResponse);
        Passport::tokensCan([
            'openid' => 'Verify your identity',
            'profile' => 'View your name and account type',
            'email' => 'View your email address',
            Scopes::ADMIN => 'Administer this single sign-on server',
        ]);
        Passport::tokensExpireIn(CarbonInterval::minutes(config('sso.access_token_minutes')));
        Passport::refreshTokensExpireIn(CarbonInterval::hours(config('sso.refresh_token_hours')));

        RateLimiter::for('sso-login', fn (Request $request) => $this->perAddress($request, 'login_per_minute'));
        RateLimiter::for('sso-token', fn (Request $request) => $this->perAddress($request, 'token_per_minute'));

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(SecurityScheme::oauth2()->flow('authorizationCode', function (OAuthFlow $flow) {
                $issuer = config('sso.issuer');
                $flow->authorizationUrl("$issuer/oauth2/authorize")->tokenUrl("$issuer/oauth2/token")
                    ->refreshUrl("$issuer/oauth2/token");
                foreach (Scopes::ALLOWED as $scope) {
                    $flow->addScope($scope);
                }
            }));
        });
    }

    private function perAddress(Request $request, string $setting): Limit
    {
        return Limit::perMinute((int) config("sso.rate_limit.$setting"))->by((string) $request->ip())
            ->response(fn (Request $request, array $headers) => response(
                'Too many attempts from your network. Wait a minute and try again.',
                429,
                $headers + ['Content-Type' => 'text/plain; charset=UTF-8'],
            ));
    }
}
