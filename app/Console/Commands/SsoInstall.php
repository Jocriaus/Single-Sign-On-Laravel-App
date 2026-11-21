<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\Passport;

class SsoInstall extends Command
{
    protected $signature = 'sso:install';

    protected $description = 'Create the signing key pair if none exists (never overwrites)';

    public function handle(): int
    {
        $configured = config('passport.private_key') || config('passport.public_key');
        $files = is_file(Passport::keyPath('oauth-private.key')) || is_file(Passport::keyPath('oauth-public.key'));

        if ($configured || $files) {
            $this->info('Signing key already exists.');

            return self::SUCCESS;
        }

        $this->call('passport:keys');

        return self::SUCCESS;
    }
}
