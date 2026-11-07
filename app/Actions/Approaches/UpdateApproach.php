<?php

namespace App\Actions\Approaches;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class UpdateApproach
{
    public function execute(?User $actor, string $publicId, array $data): never
    {
        throw new AuthorizationException('تعديل بيانات المنهجية متوقف حتى اعتماد سجل التغييرات والصلاحيات.');
    }
}
