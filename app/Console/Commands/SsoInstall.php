<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Role;
use App\Sso\Apps\ClientRegistrar;
use Illuminate\Console\Command;
use Laravel\Passport\Passport;

class SsoInstall extends Command
{
    public const ADMIN_CLIENT_ID = 'sso-admin-console';

    protected $signature = 'sso:install';

    protected $description = 'Create the signing key pair and the admin console client if missing (never overwrites)';

    public function handle(ClientRegistrar $registrar): int
    {
        $this->signingKey();

        return $this->adminClient($registrar);
    }

    private function signingKey(): void
    {
        $configured = config('passport.private_key') || config('passport.public_key');
        $files = is_file(Passport::keyPath('oauth-private.key')) || is_file(Passport::keyPath('oauth-public.key'));

        if ($configured || $files) {
            $this->info('Signing key already exists.');

            return;
        }

        $this->call('passport:keys');
    }

    private function adminClient(ClientRegistrar $registrar): int
    {
        $secret = (string) config('sso.admin_client_secret');
        if ($secret === '') {
            $this->line('Admin console client not created: SSO_ADMIN_CLIENT_SECRET is not set.');

            return self::SUCCESS;
        }

        if (Client::whereKey(self::ADMIN_CLIENT_ID)->exists()) {
            $this->info('Admin console client already exists; left unchanged.');

            return self::SUCCESS;
        }

        if (Role::whereIn('role_name', ['ICTO_ADMIN', 'ADMIN'])->count() !== 2) {
            $this->error('Run the database seeder first (php artisan db:seed).');

            return self::FAILURE;
        }

        $registrar->register([
            'name' => 'SSO Admin Console',
            'redirect_uris' => config('sso.issuer').'/docs/api',
            'scopes' => 'openid profile email sso.admin',
            'allowed_roles' => 'ICTO_ADMIN, ADMIN',
            'require_pkce' => true,
        ], self::ADMIN_CLIENT_ID, $secret);

        $this->info("Created the '".self::ADMIN_CLIENT_ID."' client for the API documentation page.");

        return self::SUCCESS;
    }
}
