<?php

namespace App\Providers;

use App\Sso\OAuth\Scopes;
use App\Sso\OAuth\SigningKey;
use Carbon\CarbonInterval;
use Illuminate\Support\ServiceProvider;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
