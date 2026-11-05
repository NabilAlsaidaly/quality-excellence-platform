<?php

namespace App\Policies;

use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\User;

final class InstitutionAccountPolicy
{
    public function view(User $user, string $organization): bool
    {
        // Own account/session information only. No tenant-content capability.
        $context = app(TenantContext::class);

        return $context->verifiedFor($user)
            && User::query()->whereKey($user->id)->where('principal_type', 'institution')
                ->where('organization_id', $context->organizationId())->exists()
            && Organization::query()->whereKey($context->organizationId())->where('public_id', $organization)->exists();
    }
}
