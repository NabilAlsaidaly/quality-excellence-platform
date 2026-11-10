<?php

namespace App\Domain\Maturity;

use DomainException;
use Illuminate\Support\Str;

/** Identity-only input from a future authorized SQL read; not clean-file/access/acceptance proof. */
final readonly class StageEvidenceReference
{
    public function __construct(public string $organizationPublicId, public string $questionPublicId,
        public string $expectedEvidencePublicId, public string $documentPublicId, public string $versionPublicId)
    {
        foreach ([$organizationPublicId, $questionPublicId, $expectedEvidencePublicId, $documentPublicId, $versionPublicId] as $id) {
            if (! Str::isUlid($id)) {
                throw new DomainException('هوية مرجع الشاهد غير صالحة.');
            }
        }
    }

    public function snapshot(): array
    {
        return ['organization_public_id' => strtolower($this->organizationPublicId), 'question_public_id' => strtolower($this->questionPublicId),
            'expected_evidence_public_id' => strtolower($this->expectedEvidencePublicId), 'document_public_id' => strtolower($this->documentPublicId),
            'version_public_id' => strtolower($this->versionPublicId)];
    }
}
