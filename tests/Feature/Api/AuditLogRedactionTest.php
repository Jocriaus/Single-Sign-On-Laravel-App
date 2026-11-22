<?php

namespace Tests\Feature\Api;

use App\Models\ApiAuditLog;
use App\Models\User;
use App\Sso\Api\SensitiveDataMasker;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class AuditLogRedactionTest extends TestCase
{
    use OAuthFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** @return array{0: User, 1: array} */
    private function adminTokens(): array
    {
        $icto = User::factory()->adminType()->withRole('ICTO_ADMIN')->create();
        $app = $this->registerApp(['scopes' => 'openid profile email sso.admin']);

        return [$icto, $this->tokensFor($icto, $app, 'openid profile email sso.admin')];
    }

    public function test_every_api_call_is_recorded_with_user_and_app(): void
    {
        [$icto, $tokens] = $this->adminTokens();

        $this->withToken($tokens['access_token'])->withHeader('User-Agent', 'audit-test')->getJson('/api/v1/user/me')->assertOk();

        $row = ApiAuditLog::where('endpoint', '/api/v1/user/me')->firstOrFail();
        $this->assertSame('GET', $row->http_method);
        $this->assertSame(200, $row->status_code);
        $this->assertSame('success', $row->status);
        $this->assertSame($icto->id, (int) $row->user_id);
        $this->assertSame($tokens['client']->id, $row->client_id);
        $this->assertNotEmpty($row->ip_address);
        $this->assertSame('audit-test', $row->user_agent);
        $this->assertNull($row->request_body);
        $this->assertSame('Profile loaded.', $row->message);

        $this->withToken($tokens['access_token'])->getJson('/api/v1/admin/user/999999')->assertStatus(404);
        $error = ApiAuditLog::where('status_code', 404)->firstOrFail();
        $this->assertSame('error', $error->status);
        $this->assertSame('NOT_FOUND', $error->error_code);
    }

    public function test_secrets_never_reach_the_audit_trail(): void
    {
        [, $tokens] = $this->adminTokens();

        $secret = $this->withToken($tokens['access_token'])->postJson('/api/v1/client', [
            'name' => 'Audited', 'redirectUris' => ['https://a.test/cb'], 'password' => 'hunter2-hunter2',
        ])->assertOk()->json('data.clientSecret');

        $row = ApiAuditLog::where('http_method', 'POST')->firstOrFail();
        $this->assertStringContainsString('"clientSecret":"***"', $row->response_body);
        $this->assertStringNotContainsString($secret, $row->response_body);
        $this->assertStringContainsString('"password":"***"', $row->request_body);
        $this->assertStringNotContainsString('hunter2', $row->request_body);

        $temporary = $this->withToken($tokens['access_token'])->postJson('/api/v1/admin/user', [
            'userType' => 'Student', 'studentNumber' => '2026-777', 'email' => 's@bulsu.test', 'firstName' => 'S', 'lastName' => 'T',
        ])->json('data.temporaryPassword');
        $this->assertNotEmpty($temporary);
        $this->assertStringNotContainsString($temporary, ApiAuditLog::orderByDesc('id')->first()->response_body);
    }

    public function test_unauthenticated_calls_are_recorded_too(): void
    {
        $this->getJson('/api/v1/user/me')->assertStatus(401);

        $row = ApiAuditLog::firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertNull($row->client_id);
        $this->assertSame(401, $row->status_code);
        $this->assertSame('UNAUTHORIZED', $row->error_code);
    }

    public function test_the_masker_hides_keys_at_any_depth_and_omits_non_json(): void
    {
        $masker = new SensitiveDataMasker;

        $masked = $masker->mask('{"a":{"Refresh_Token":"x","list":[{"id-token":"y","ok":1}]},"new-password":"z","Authorization":"Bearer q","temporaryPassword":"t"}');
        $this->assertSame('{"a":{"Refresh_Token":"***","list":[{"id-token":"***","ok":1}]},"new-password":"***","Authorization":"***","temporaryPassword":"***"}', $masked);
        $this->assertSame('[non-JSON body omitted]', $masker->mask('grant_type=password&password=x'));
        $this->assertSame('[non-JSON body omitted]', $masker->mask('12'));
        $this->assertNull($masker->mask(null));
        $this->assertSame(10000, strlen($masker->mask('{"a":"'.str_repeat('b', 20000).'"}')));
    }
}
