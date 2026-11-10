<?php

namespace App\Domain\Reviews;

use DomainException;
use Illuminate\Support\Str;

/** Immutable input from a trusted application read; this object grants no access. */
final readonly class ReviewTarget
{
    /** @var list<string> Server-resolved contributors, including creator and last editor. */
    public array $contributorPublicIds;

    public function __construct(
        public string $organizationPublicId,
        public ReviewTargetKind $kind,
        public string $targetPublicId,
        public string $revisionPublicId,
        array $contributorPublicIds,
        public string $fingerprint,
    ) {
        foreach ([$organizationPublicId, $targetPublicId, $revisionPublicId] as $id) {
            if (! Str::isUlid($id)) {
                throw new DomainException('هوية هدف المراجعة غير صالحة.');
            }
        }
        if (! array_is_list($contributorPublicIds) || $contributorPublicIds === []
            || ! preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)) {
            throw new DomainException('سند المراجعة غير مكتمل.');
        }
        foreach ($contributorPublicIds as $id) {
            if (! is_string($id) || ! Str::isUlid($id)) {
                throw new DomainException('هوية المساهم غير صالحة.');
            }
        }
        $this->contributorPublicIds = array_values(array_unique(array_map(strtolower(...), $contributorPublicIds)));
    }

    public function sameTarget(self $other): bool
    {
        return strcasecmp($this->organizationPublicId, $other->organizationPublicId) === 0
            && $this->kind === $other->kind && strcasecmp($this->targetPublicId, $other->targetPublicId) === 0;
    }
}
