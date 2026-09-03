<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

enum DrillKind: string
{
    /** Income-statement style: debit and credit sums within the column range. */
    case PeriodMovements = 'period_movements';

    /** Balance-sheet style: every movement that built the position at the date. */
    case CumulativeBalance = 'cumulative_balance';

    /** Cash-flow style: opening balance, period movements, closing balance. */
    case BalanceChange = 'balance_change';

    /** Arithmetic over named sub-lines with signed contributions. */
    case Composite = 'composite';

    /** Sum of the top-level rows within one statement section. */
    case SectionBreakdown = 'section_breakdown';
}
