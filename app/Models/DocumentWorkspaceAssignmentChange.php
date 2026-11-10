<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class DocumentWorkspaceAssignmentChange extends Model
{
    use HasPublicUlid;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'fixture_id', 'assignment_id', 'document_id', 'scope_key', 'actor_id', 'subject_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Document assignment events are append-only.'));
        static::deleting(fn () => throw new LogicException('Document assignment events cannot be deleted.'));
    }
}
