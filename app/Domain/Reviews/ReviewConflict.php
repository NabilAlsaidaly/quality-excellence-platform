<?php

namespace App\Domain\Reviews;

use DomainException;

/** Future application adapters map a stale immutable target to a conflict response. */
final class ReviewConflict extends DomainException {}
