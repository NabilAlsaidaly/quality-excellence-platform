<?php

namespace App\Domain\Documents\Intake;

/** Structural inspection only: neither antivirus proof, release nor access authority. */
final readonly class InspectedDocument
{
    public function __construct(public string $originalFilename, public string $mediaType, public int $byteSize, public string $sha256) {}
}
