<?php

namespace Tests\Feature\Ops;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_is_public_and_shows_only_the_status(): void
    {
        $this->getJson('/actuator/health')->assertOk()->assertExactJson(['status' => 'UP']);
    }

    public function test_health_check_reports_down_when_the_database_is_unreachable(): void
    {
        $this->app->bind(\App\Sso\HealthProbe::class, fn () => new class extends \App\Sso\HealthProbe {
            public function databaseIsUp(): bool
            {
                return false;
            }
        });

        $this->getJson('/actuator/health')->assertStatus(503)->assertExactJson(['status' => 'DOWN']);
    }
}
