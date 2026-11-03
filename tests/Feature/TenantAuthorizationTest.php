<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantAuthorizationScenarios;
use Tests\TestCase;

class TenantAuthorizationTest extends TestCase
{
    use RefreshDatabase, TenantAuthorizationScenarios;
}
