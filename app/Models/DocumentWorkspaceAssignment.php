<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Sealed TEST_ONLY grant. Only the explicit locked lifecycle Action may mutate it. */
class DocumentWorkspaceAssignment extends Model
{
    use HasPublicUlid;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'fixture_id', 'grantee_id', 'issuer_id', 'document_id', 'scope_key', 'revoked_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Document assignment changes require the locked lifecycle Action.'));
        static::deleting(fn () => throw new LogicException('Document assignment history is preserved.'));
    }
}
