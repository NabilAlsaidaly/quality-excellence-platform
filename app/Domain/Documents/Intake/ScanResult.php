<?php

namespace App\Domain\Documents\Intake;

/** Scanner evidence only. Clean is neither document approval nor permission to serve. */
enum ScanResult: string
{
    case Clean = 'clean';
    case Suspect = 'suspect';
    case Unavailable = 'unavailable';
}
