<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ApproachChange extends Model
{
    use HasPublicUlid;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'approach_id', 'actor_id', 'previous_owner_id', 'new_owner_id'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Approach audit records cannot be overwritten.');
        });
        static::deleting(function (): void {
            throw new LogicException('Approach audit records cannot be deleted.');
        });
    }

    public function approach(): BelongsTo
    {
        return $this->belongsTo(Approach::class)->where('organization_id', $this->organization_id);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
