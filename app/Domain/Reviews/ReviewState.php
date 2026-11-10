<?php

namespace App\Domain\Reviews;

enum ReviewState: string
{
    case Pending = 'pending';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Superseded = 'superseded';
}
