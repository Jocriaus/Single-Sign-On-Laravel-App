<?php

namespace Tests\Feature\Ops;

use App\Models\SignIn;
use App\Models\User;
use App\Sso\Activity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class CleanupTest extends TestCase
{
    use OAuthFlow;

    private function cleanup(): void
    {
        $this->artisan('sso:cleanup')->assertExitCode(0);
    }

    private function audit(int $daysAgo): void
    {
        DB::table('api_audit_logs')->insert([
            'endpoint' => '/api/v1/x', 'http_method' => 'GET', 'status_code' => 200, 'status' => 'SUCCESS',
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_a_sign_in_whose_tokens_have_all_expired_is_removed(): void
    {
        $this->tokensFor(User::factory()->student()->create());
        $this->assertSame(1, SignIn::count());

        $this->travel(9)->hours();
        $this->cleanup();

        $this->assertSame(0, SignIn::count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->count());
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_a_sign_in_with_a_valid_refresh_token_is_kept(): void
    {
        $tokens = $this->tokensFor(User::factory()->student()->create());

        $this->travel(20)->minutes();
        $this->cleanup();

        $this->assertSame(1, SignIn::count());
        $this->assertSame(1, DB::table('oauth_refresh_tokens')->count());
        $this->refresh($tokens, $tokens['refresh_token'])->assertOk();
    }

    public function test_cleanup_keeps_what_a_live_refresh_token_needs(): void
    {
        $tokens = $this->tokensFor(User::factory()->student()->create());

        $this->travel(20)->minutes();
        $this->cleanup();

        $this->assertSame(1, DB::table('oauth_access_tokens')->count());
        $this->refresh($tokens, $tokens['refresh_token'])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function test_cleanup_after_a_rotation_keeps_the_chain_working(): void
    {
        $tokens = $this->tokensFor(User::factory()->student()->create());
        $second = $this->refresh($tokens, $tokens['refresh_token'])->assertOk()->json();

        $this->travel(20)->minutes();
        $this->cleanup();

        $this->refresh($tokens, $second['refresh_token'])->assertOk();
    }

    public function test_abandoned_sign_ins_are_removed_after_eight_hours(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->student()->create());
        $this->codeFrom($this->authorize($app['client'], ['code_challenge' => $this->challenge('v'), 'state' => 's']));
        $this->assertSame(1, SignIn::count());
        $this->assertSame(1, DB::table('oauth_auth_codes')->count());

        $this->travel(7)->hours();
        $this->cleanup();
        $this->assertSame(1, SignIn::count());

        $this->travel(2)->hours();
        $this->cleanup();
        $this->assertSame(0, SignIn::count());
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_old_api_audit_rows_are_removed_and_recent_ones_kept(): void
    {
        $this->audit(366);
        $this->audit(364);

        $this->cleanup();

        $this->assertSame(1, DB::table('api_audit_logs')->count());
    }

    public function test_retention_of_zero_keeps_everything(): void
    {
        config(['sso.api_audit_retention_days' => 0]);
        $this->audit(4000);

        $this->cleanup();

        $this->assertSame(1, DB::table('api_audit_logs')->count());
    }

    public function test_activity_logs_are_never_removed(): void
    {
        Activity::log(null, 'Old thing', Activity::AUTH);
        DB::table('activity_logs')->update(['created_at' => now()->subYears(5)]);

        $this->travel(30)->days();
        $this->cleanup();

        $this->assertSame(1, DB::table('activity_logs')->count());
    }

    public function test_cleanup_is_safe_to_run_twice(): void
    {
        $this->tokensFor(User::factory()->student()->create());
        $this->audit(400);
        $this->travel(9)->hours();

        $this->cleanup();
        $this->cleanup();

        $this->assertSame(0, SignIn::count());
        $this->assertSame(0, DB::table('api_audit_logs')->count());
    }

    public function test_it_prints_and_logs_the_summary(): void
    {
        $this->audit(400);
        Log::spy();

        $this->artisan('sso:cleanup')
            ->expectsOutput('Nightly cleanup removed 0 expired app sign-ins and 1 old API audit rows')
            ->assertExitCode(0);

        Log::shouldHaveReceived('info')->with('Nightly cleanup removed 0 expired app sign-ins and 1 old API audit rows')->once();
    }

    public function test_it_is_scheduled_daily_at_0230(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'sso:cleanup'));

        $this->assertNotNull($event);
        $this->assertSame('30 2 * * *', $event->expression);
    }
}
