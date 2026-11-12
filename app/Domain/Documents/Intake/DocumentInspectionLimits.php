<?php

namespace App\Domain\Documents\Intake;

use InvalidArgumentException;

/** Caller-selected technical bounds; no operational D007 defaults are supplied. */
final readonly class DocumentInspectionLimits
{
    /** Engineering Lead's bounded TEST_ONLY D007 decision; not operational grant ratification. */
    public const MAXIMUM_VERSION_BYTES = 30000000;

    public function __construct(public int $maximumBytes, public int $maximumExpandedBytes, public int $maximumArchiveEntries)
    {
        if ($maximumBytes < 1 || $maximumBytes > self::MAXIMUM_VERSION_BYTES || $maximumExpandedBytes < $maximumBytes
            || $maximumExpandedBytes >= PHP_INT_MAX || $maximumArchiveEntries < 1) {
            throw new InvalidArgumentException('حدد حدود الفحص التقنية صراحة.');
        }
    }
}
