<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class DocumentIntakeEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'fixture_id', 'intake_id', 'actor_id', 'details_json'];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'details_json' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::saving(fn () => throw new LogicException('Intake events are append-only.'));
        self::deleting(fn () => throw new LogicException('Intake events are append-only.'));
    }
}
