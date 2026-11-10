<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class DocumentMetadataRevision extends Model
{
    use HasPublicUlid;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'document_id', 'actor_id'];

    protected function casts(): array
    {
        return ['version_number' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Document metadata revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Document metadata history is preserved.'));
    }
}
