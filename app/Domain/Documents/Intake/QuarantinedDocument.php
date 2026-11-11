<?php

namespace App\Domain\Documents\Intake;

/** Internal trusted storage receipt. Not an HTTP DTO or permission/release token. */
final readonly class QuarantinedDocument
{
    public function __construct(public string $organizationPublicId, public string $intakePublicId,
        public string $storageKey, public InspectedDocument $inspection) {}

    public function isReleased(): bool
    {
        return false;
    }
}
