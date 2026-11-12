<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class MaturityReassessmentDecision extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'fixture_id', 'approach_id', 'stage_id', 'attempt_id',
        'submission_id', 'review_id', 'reviewer_id', 'policy_ref'];

    protected function casts(): array
    {
        return ['selected_band_index' => 'integer', 'snapshot_json' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::saving(fn () => throw new LogicException('Reassessment decisions are immutable.'));
        self::deleting(fn () => throw new LogicException('Reassessment decisions are immutable.'));
    }
}
