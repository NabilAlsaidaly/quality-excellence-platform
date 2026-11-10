<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Read aggregate only. Pending transitions occur exclusively in locked application Actions. */
final class ReviewMaturityReview extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::saving(fn () => throw new LogicException('Use the authorized review transaction.'));
        self::deleting(fn () => throw new LogicException('Review history is immutable.'));
    }
}
