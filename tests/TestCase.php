<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // HTTP assertions run before the separate frontend build in CI.
        // Real asset resolution is covered by the build and HTTP/browser checks.
        $this->withoutVite();
    }
}
