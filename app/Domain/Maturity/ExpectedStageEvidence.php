<?php

namespace App\Domain\Maturity;

use DomainException;
use Illuminate\Support\Str;

/** A configured expectation, not a finding that a document is adequate or authorized. */
final readonly class ExpectedStageEvidence
{
    public function __construct(public string $publicId, public string $description, public bool $required)
    {
        if (! Str::isUlid($publicId) || ! mb_check_encoding($description, 'UTF-8') || mb_strlen($description) > 65535
            || preg_match('/[^\p{Z}\s]/u', $description) !== 1) {
            throw new DomainException('تعريف الشاهد المتوقع غير صالح.');
        }
    }

    public function snapshot(): array
    {
        return ['public_id' => strtolower($this->publicId), 'description' => $this->description, 'required' => $this->required];
    }
}
