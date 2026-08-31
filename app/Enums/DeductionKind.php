<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Qoyod's manual deduction kinds: مخالفة أنظمة، أخرى. Advance recovery is
 * never a manual kind — the run computes it itself.
 */
enum DeductionKind: string implements HasLabel
{
    case Violation = 'violation';
    case Other = 'other';

    public function getLabel(): string
    {
        return __("payroll.deduction_kind.{$this->value}");
    }
}
