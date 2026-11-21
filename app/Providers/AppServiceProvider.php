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
    }
}
