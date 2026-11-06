<?php

namespace Tests\Integration\MySql;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\InstitutionIdentityScenarios;
use Tests\Support\MySqlTestCase;

class InstitutionIdentityTest extends MySqlTestCase
{
    use DatabaseMigrations, InstitutionIdentityScenarios;

    protected function setUp(): void
    {
        parent::setUp();
        // All fixture DML is undone before the ordinary migration teardown.
        // A semantic rollback must never discard institutional accounts/tokens.
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->app && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }
}
