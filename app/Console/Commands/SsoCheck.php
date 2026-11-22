<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SsoCheck extends Command
{
    protected $signature = 'sso:check';

    protected $description = 'Check the settings that must be safe in production (run it when deploying)';

    public function handle(): int
    {
        if (! app()->environment('production')) {
            $this->line('Not production: nothing to check.');

            return self::SUCCESS;
        }

        $problems = self::problems(
            (string) config('sso.issuer'),
            (bool) config('session.secure'),
            (bool) config('app.debug'),
            (string) config('sso.admin_client_secret'),
        );

        if ($problems === []) {
            $this->info('Production settings look safe.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }

    /** @return list<string> */
    public static function problems(string $issuer, bool $secureCookie, bool $debug, string $adminClientSecret): array
    {
        $problems = [];

        if (! str_starts_with(strtolower($issuer), 'https://')) {
            $problems[] = "SSO_ISSUER must be the public https:// address of this server (is $issuer).";
        }
        $host = strtolower((string) parse_url($issuer, PHP_URL_HOST));
        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            $problems[] = 'SSO_ISSUER must be the public address of this server, not localhost.';
        }
        if (! $secureCookie) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true so the SSO session cookie is only sent over HTTPS.';
        }
        if ($debug) {
            $problems[] = 'APP_DEBUG must be false in production.';
        }
        if ($adminClientSecret !== '' && (strlen($adminClientSecret) < 24
            || in_array(strtolower($adminClientSecret), ['change-me', 'changeme', 'secret', 'password'], true))) {
            $problems[] = 'SSO_ADMIN_CLIENT_SECRET must be a random value of at least 24 characters (or unset).';
        }

        return $problems;
    }
}
