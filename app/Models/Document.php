<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Document extends Model
{
    use HasPublicUlid;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'created_by'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Document identity cannot be overwritten.');
        });
        static::deleting(function (): void {
            throw new LogicException('Document deletion is not approved.');
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->where('organization_id', $this->organization_id);
    }
}
