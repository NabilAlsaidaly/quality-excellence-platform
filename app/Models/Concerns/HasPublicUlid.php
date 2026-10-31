<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use LogicException;

trait HasPublicUlid
{
    use HasUlids;

    public static function bootHasPublicUlid(): void
    {
        static::updating(function ($model): void {
            if ($model->isDirty('public_id')) {
                throw new LogicException('A persisted public identifier is immutable.');
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
