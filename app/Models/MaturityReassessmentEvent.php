<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class MaturityReassessmentEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'fixture_id', 'approach_id', 'stage_id', 'attempt_id',
        'actor_id', 'submission_id', 'details_json'];

    protected function casts(): array
    {
        return ['details_json' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::saving(fn () => throw new LogicException('Reassessment events are append-only.'));
        self::deleting(fn () => throw new LogicException('Reassessment events are append-only.'));
    }
}
