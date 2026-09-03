<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * Which date window a drill reference resolves against the active column.
 *
 * Most ledger drills follow the column's own range. Opening balances and the
 * balance sheet's derived result lines need a different anchor so the drill
 * explains the same figure the report already showed.
 */
enum DrillDateWindow: string
{
    /** The statement column's own range. */
    case Period = 'period';

    /** Strictly before the column period starts — an opening balance. */
    case BeforePeriodStart = 'before_period_start';

    /** Revenue and expenses accumulated before the fiscal year containing the column end. */
    case BeforeFiscalYearStart = 'before_fiscal_year_start';

    /** Revenue and expenses from the fiscal year start through the column end. */
    case FiscalYearToPeriodEnd = 'fiscal_year_to_period_end';
}
