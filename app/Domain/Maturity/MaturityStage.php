<?php

namespace App\Domain\Maturity;

/** Fixed vocabulary only; no authorization, unlock or reassessment decisions. */
enum MaturityStage: string
{
    case Orientation = 'orientation';
    case System = 'system';
    case Implementation = 'implementation';
    case Measurement = 'measurement';
    case Improvement = 'improvement';

    public function label(): string
    {
        return match ($this) {
            self::Orientation => 'التوجه',
            self::System => 'النظام',
            self::Implementation => 'التنفيذ',
            self::Measurement => 'القياس',
            self::Improvement => 'التحسين والتطوير',
        };
    }
}
