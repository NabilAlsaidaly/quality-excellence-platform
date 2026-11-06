<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InstitutionIdentityScenarios;
use Tests\TestCase;

class InstitutionIdentityTest extends TestCase
{
    use InstitutionIdentityScenarios, RefreshDatabase;
}
