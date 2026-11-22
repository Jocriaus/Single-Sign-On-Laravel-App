<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SsoCleanup extends Command
{
    protected $signature = 'sso:cleanup';

    protected $description = 'Remove expired tokens, codes, abandoned app sign-ins and old API audit rows';

    public function handle(): int
    {
        $now = now();

        // A refresh request finds its sign-in through the old access token, so an access token stays
        // while any refresh token still points at it.
        DB::transaction(fn () => DB::table('oauth_refresh_tokens')->where('expires_at', '<', $now)->delete());

        DB::transaction(fn () => DB::table('oauth_access_tokens')->where('expires_at', '<', $now)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('oauth_refresh_tokens')
                ->whereColumn('oauth_refresh_tokens.access_token_id', 'oauth_access_tokens.id'))
            ->delete());

        DB::transaction(fn () => DB::table('oauth_auth_codes')->where('expires_at', '<', $now)->delete());

        $signIns = DB::transaction(fn () => DB::table('sso_sign_ins')
            ->where('started_at', '<', $now->copy()->subHours((int) config('sso.max_app_session_hours')))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('oauth_auth_codes')
                ->whereColumn('oauth_auth_codes.sign_in_id', 'sso_sign_ins.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('oauth_access_tokens')
                ->whereColumn('oauth_access_tokens.sign_in_id', 'sso_sign_ins.id'))
            ->delete());

        $days = (int) config('sso.api_audit_retention_days');
        $audit = $days > 0
            ? DB::transaction(fn () => DB::table('api_audit_logs')->where('created_at', '<', $now->copy()->subDays($days))->delete())
            : 0;

        $summary = "Nightly cleanup removed $signIns expired app sign-ins and $audit old API audit rows";
        $this->info($summary);
        Log::info($summary);

        return self::SUCCESS;
    }
}
