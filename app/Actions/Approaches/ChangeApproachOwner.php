<?php

namespace App\Actions\Approaches;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class ChangeApproachOwner
{
    public function execute(?User $actor, string $publicId, string $ownerPublicId): never
    {
        throw new AuthorizationException('نقل الملكية متوقف حتى اعتماد سجل التغييرات وآلية التفويض.');
    }
}
