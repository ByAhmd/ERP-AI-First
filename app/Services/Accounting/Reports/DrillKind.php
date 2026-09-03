<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * How a statement row maps to underlying ledger detail.
 *
 * The kind is fixed when the report builds the row, so drill execution never
 * has to guess whether a figure is cumulative, a period movement, or a balance
 * change across the window.
 */
enum DrillKind: string
{
    /** Income-statement style: debit and credit sums within the column range. */
    case PeriodMovements = 'period_movements';

    /** Balance-sheet style: every movement that built the position at the date. */
    case CumulativeBalance = 'cumulative_balance';

    /** Cash-flow style: opening balance, period movements, closing balance. */
    case BalanceChange = 'balance_change';
}
