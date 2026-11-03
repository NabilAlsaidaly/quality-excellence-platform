<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OrganizationalHierarchyScenarios;
use Tests\TestCase;

class OrganizationalHierarchyTest extends TestCase
{
    use OrganizationalHierarchyScenarios, RefreshDatabase;
}
