<?php

use App\Models\Organization;

return [
    // Register implemented capabilities as 'stable.name' => Resource::class.
    // Empty until a separately reviewed feature defines its policy and scope.
    'capabilities' => [],
    // Only the trusted request-bound TEST_ONLY runner installs this temporarily.
    // Configuration, browser input and an existing role cannot enable a fixture.
    'test_only_fixture_capabilities' => [
        'approaches.access' => Organization::class,
    ],
];
