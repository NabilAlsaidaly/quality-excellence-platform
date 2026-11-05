<?php

namespace App\Identity;

use Illuminate\Auth\EloquentUserProvider;

final class LegacyUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        // Existing web/Filament compatibility must never email-resolve an
        // institution or platform principal through the historical namespace.
        return parent::newModelQuery($model)->where('principal_type', 'legacy');
    }
}
