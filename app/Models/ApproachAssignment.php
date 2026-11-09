<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** TEST_ONLY candidate; lifecycle mutations require the approved locked Action. */
class ApproachAssignment extends Model
{
    use HasPublicUlid;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'grantee_id', 'issuer_id', 'approach_id', 'scope_key', 'initial_contributor_id', 'revoked_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Assignment changes require the explicit locked lifecycle Action.'));
        static::deleting(fn () => throw new LogicException('Assignment history cannot be deleted through the model.'));
    }
}
