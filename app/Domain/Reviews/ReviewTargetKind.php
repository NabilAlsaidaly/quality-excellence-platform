<?php

namespace App\Domain\Reviews;

/** Finite domain vocabulary, not an arbitrary model-class relationship. */
enum ReviewTargetKind: string
{
    case ApproachContent = 'approach_content';
    case DocumentVersion = 'document_version';
    case MaturitySubmission = 'maturity_submission';
}
