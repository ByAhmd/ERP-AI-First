<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Qoyod's advance kinds: سلفة and مقدم راتب. Same accounting, different
 * word on the document.
 */
enum AdvanceKind: string implements HasLabel
{
    case Advance = 'advance';
    case SalaryAdvance = 'salary_advance';

    public function getLabel(): string
    {
        return __("payroll.advance_kind.{$this->value}");
    }
}
