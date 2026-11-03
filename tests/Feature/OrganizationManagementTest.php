<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OrganizationManagementScenarios;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use OrganizationManagementScenarios, RefreshDatabase;
}
