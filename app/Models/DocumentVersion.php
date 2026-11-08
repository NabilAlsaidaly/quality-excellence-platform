<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DocumentVersion extends Model
{
    use HasPublicUlid;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'document_id', 'actor_id', 'storage_disk', 'storage_key',
        'sha256', 'byte_size', 'original_filename', 'detected_media_type'];

    protected function casts(): array
    {
        return ['version_number' => 'integer', 'byte_size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Historical document versions cannot be overwritten.');
        });
        static::deleting(function (): void {
            throw new LogicException('Historical document versions cannot be deleted.');
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class)->where('organization_id', $this->organization_id);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
