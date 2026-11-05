<?php

namespace Tests\Integration\MySql;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\MySqlTestCase;
use Tests\Support\TenantAuthorizationScenarios;

class TenantAuthorizationTest extends MySqlTestCase
{
    use DatabaseMigrations, TenantAuthorizationScenarios;
}
