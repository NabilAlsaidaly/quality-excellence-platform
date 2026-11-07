<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Approach extends Model
{
    use HasPublicUlid;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'owning_unit_id', 'created_by'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Approach changes require an approved historical change contract.');
        });
        static::deleting(function (): void {
            throw new LogicException('Approach deletion is not an approved operation.');
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owningUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'owning_unit_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ApproachContentRevision::class)->where('organization_id', $this->organization_id);
    }
}
