<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_framework_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_unknown_route_returns_not_found(): void
    {
        $this->get('/unknown-bootstrap-route')->assertNotFound();
    }

    public function test_framework_migrations_can_run_in_isolation(): void
    {
        foreach (['users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }
}
