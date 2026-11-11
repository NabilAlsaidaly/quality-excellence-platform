<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Read aggregate; processing transitions belong to guarded CAS application transactions. */
final class DocumentIntake extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'fixture_id', 'document_id', 'version_id', 'actor_id',
        'request_hash', 'lease_hash', 'release_actor_id', 'release_authority_ref', 'limits_json'];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'limits_json' => 'array', 'lease_expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::saving(fn () => throw new LogicException('Use the guarded intake transaction.'));
        self::deleting(fn () => throw new LogicException('Intake history cannot be deleted.'));
    }
}
