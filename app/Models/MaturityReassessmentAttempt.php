<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Read aggregate; approved pairs and revisions change only in guarded application transactions. */
final class MaturityReassessmentAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'fixture_id', 'approach_id', 'stage_id', 'author_id',
        'configuration_id', 'initial_assessment_id', 'previous_attempt_id', 'approved_submission_id', 'approved_review_id', 'request_hash'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'attempt_number' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::saving(fn () => throw new LogicException('Use the guarded reassessment transaction.'));
        self::deleting(fn () => throw new LogicException('Reassessment history is preserved.'));
    }
}
