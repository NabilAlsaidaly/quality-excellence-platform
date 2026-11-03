<?php

namespace Tests\Integration\MySql;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\MySqlTestCase;
use Tests\Support\OrganizationManagementScenarios;

class OrganizationManagementTest extends MySqlTestCase
{
    use DatabaseMigrations, OrganizationManagementScenarios;
}
