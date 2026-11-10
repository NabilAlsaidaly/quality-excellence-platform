<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class MaturityWorkflowSubmission extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['content_json' => 'array'];
    }

    protected static function booted(): void
    {
        self::saving(fn () => throw new LogicException('Submission history is immutable.'));
        self::deleting(fn () => throw new LogicException('Submission history is immutable.'));
    }
}
