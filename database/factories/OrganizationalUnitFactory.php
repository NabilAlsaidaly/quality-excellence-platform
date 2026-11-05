<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationalUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationalUnit> */
class OrganizationalUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'parent_id' => null,
            'name' => fake()->words(3, true),
        ];
    }

    public function childOf(OrganizationalUnit $parent): static
    {
        return $this->state(fn () => [
            'organization_id' => $parent->organization_id,
            'parent_id' => $parent->getKey(),
        ]);
    }
}
