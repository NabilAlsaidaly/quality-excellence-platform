<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ApproachAssignmentChange extends Model
{
    use HasPublicUlid;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'assignment_id', 'approach_id', 'actor_id', 'subject_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Assignment events are append-only application history.'));
        static::deleting(fn () => throw new LogicException('Assignment events cannot be deleted through the model.'));
    }
}
