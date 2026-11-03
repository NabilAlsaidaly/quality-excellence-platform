<?php

namespace App\Authorization;

use App\Models\Organization;
use App\Models\OrganizationalUnit;
use InvalidArgumentException;

final class CapabilityRegistry
{
    public function resourceClass(string $capability): ?string
    {
        // Dotted capability names are literal keys, not nested configuration paths.
        $class = config('authorization.capabilities', [])[$capability] ?? null;

        return in_array($class, [Organization::class, OrganizationalUnit::class], true) ? $class : null;
    }

    public function requireRecognized(string $capability): void
    {
        if ($this->resourceClass($capability) === null) {
            throw new InvalidArgumentException('Unrecognized application capability.');
        }
    }
}
