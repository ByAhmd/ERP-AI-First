<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Services\Accounting\AccountRegistry;

/**
 * Composite drill targets shared across the four financial statements.
 *
 * Each factory names the same signed parts the report services already use, so
 * a breakdown panel explains a figure rather than inventing a second path to it.
 */
final class StatementDrillTargets
{
    public static function grossProfit(): StatementDrillTarget
    {
        return StatementDrillTarget::composite([
            new StatementDrillPart(
                label: __('accounting.statements.sections.revenue'),
                sign: 1,
                reference: StatementDrillReference::incomeSection('revenue'),
            ),
            new StatementDrillPart(
                label: __('accounting.statements.sections.cost_of_sales'),
                sign: -1,
                reference: StatementDrillReference::incomeSection('cost_of_sales'),
            ),
        ]);
    }

    public static function operatingResult(): StatementDrillTarget
    {
        return StatementDrillTarget::composite([
            new StatementDrillPart(
                label: __('accounting.statements.sections.gross_profit'),
                sign: 1,
                reference: StatementDrillReference::incomeSummary('gross_profit'),
            ),
            new StatementDrillPart(
                label: __('accounting.statements.sections.operating_expenses'),
                sign: -1,
                reference: StatementDrillReference::incomeSection('operating_expenses'),
            ),
        ]);
    }

    public static function netProfit(): StatementDrillTarget
    {
        return StatementDrillTarget::composite([
            new StatementDrillPart(
                label: __('accounting.statements.sections.operating_result'),
                sign: 1,
                reference: StatementDrillReference::incomeSummary('operating_result'),
            ),
            new StatementDrillPart(
                label: __('accounting.statements.sections.interest_tax_and_zakat'),
                sign: -1,
                reference: StatementDrillReference::incomeSection('interest_tax_and_zakat'),
            ),
        ]);
    }

    public static function interestTaxZakatPaid(AccountRegistry $registry): StatementDrillTarget
    {
        $parts = [];
        $belowTheLine = $registry->find(SystemAccount::InterestTaxAndZakat);

        if ($belowTheLine !== null) {
            $parts[] = new StatementDrillPart(
                label: __('accounting.statements.drill.interest_tax_zakat_expense'),
                sign: 1,
                reference: StatementDrillReference::ledger(
                    StatementDrillTarget::subtree(DrillKind::PeriodMovements, $belowTheLine->getKey()),
                ),
            );
        }

        $zakatPayable = $registry->find(SystemAccount::ZakatPayable);

        if ($zakatPayable !== null) {
            $parts[] = new StatementDrillPart(
                label: __('accounting.statements.drill.zakat_payable_change'),
                sign: -1,
                reference: StatementDrillReference::ledger(
                    StatementDrillTarget::account(DrillKind::BalanceChange, $zakatPayable->getKey()),
                ),
            );
        }

        return StatementDrillTarget::composite($parts);
    }

    public static function interestTaxZakatPaidOnCashFlow(AccountRegistry $registry): StatementDrillTarget
    {
        $parts = [];
        $belowTheLine = $registry->find(SystemAccount::InterestTaxAndZakat);

        if ($belowTheLine !== null) {
            $parts[] = new StatementDrillPart(
                label: __('accounting.statements.drill.interest_tax_zakat_expense'),
                sign: -1,
                reference: StatementDrillReference::ledger(
                    StatementDrillTarget::subtree(DrillKind::PeriodMovements, $belowTheLine->getKey()),
                ),
            );
        }

        $zakatPayable = $registry->find(SystemAccount::ZakatPayable);

        if ($zakatPayable !== null) {
            $parts[] = new StatementDrillPart(
                label: __('accounting.statements.drill.zakat_payable_change'),
                sign: 1,
                reference: StatementDrillReference::ledger(
                    StatementDrillTarget::account(DrillKind::BalanceChange, $zakatPayable->getKey()),
                ),
            );
        }

        return StatementDrillTarget::composite($parts);
    }

    public static function netCashChange(): StatementDrillTarget
    {
        return StatementDrillTarget::composite([
            new StatementDrillPart(
                label: __('accounting.statements.sections.operating'),
                sign: 1,
                reference: StatementDrillReference::section('operating'),
            ),
            new StatementDrillPart(
                label: __('accounting.statements.sections.investing'),
                sign: 1,
                reference: StatementDrillReference::section('investing'),
            ),
            new StatementDrillPart(
                label: __('accounting.statements.sections.financing'),
                sign: 1,
                reference: StatementDrillReference::section('financing'),
            ),
        ]);
    }

    public static function cashClosing(): StatementDrillTarget
    {
        return StatementDrillTarget::composite([
            new StatementDrillPart(
                label: __('accounting.statements.sections.cash_opening'),
                sign: 1,
                reference: StatementDrillReference::summary('cash_opening'),
            ),
            new StatementDrillPart(
                label: __('accounting.statements.sections.net_change'),
                sign: 1,
                reference: StatementDrillReference::summary('net_change'),
            ),
        ]);
    }

    /** @var list<string> */
    private const CASH_CODES = ['1110', '1120'];

    public static function cashOpening(): StatementDrillTarget
    {
        $parts = [];

        foreach (self::CASH_CODES as $code) {
            $account = Account::query()->where('code', $code)->first();

            if ($account === null) {
                continue;
            }

            $parts[] = new StatementDrillPart(
                label: app()->getLocale() === 'en' && filled($account->name_en)
                    ? $account->name_en
                    : $account->name,
                sign: 1,
                reference: StatementDrillReference::ledger(
                    StatementDrillTarget::account(DrillKind::CumulativeBalance, $account->getKey()),
                    DrillDateWindow::BeforePeriodStart,
                ),
            );
        }

        return StatementDrillTarget::composite($parts);
    }

    public static function equityOpening(): StatementDrillTarget
    {
        return StatementDrillTarget::sectionBreakdownAtOpening('equity');
    }

    public static function broughtForward(): StatementDrillTarget
    {
        return StatementDrillTarget::composite([
            new StatementDrillPart(
                label: __('accounting.statements.sections.revenue'),
                sign: 1,
                reference: StatementDrillReference::accountTypeTotal(
                    AccountType::Revenue,
                    DrillDateWindow::BeforeFiscalYearStart,
                ),
            ),
            new StatementDrillPart(
                label: __('accounting.statements.drill.expenses'),
                sign: -1,
                reference: StatementDrillReference::accountTypeTotal(
                    AccountType::Expense,
                    DrillDateWindow::BeforeFiscalYearStart,
                ),
            ),
        ]);
    }

    public static function currentResult(): StatementDrillTarget
    {
        return StatementDrillTarget::composite([
            new StatementDrillPart(
                label: __('accounting.statements.sections.revenue'),
                sign: 1,
                reference: StatementDrillReference::accountTypeTotal(
                    AccountType::Revenue,
                    DrillDateWindow::FiscalYearToPeriodEnd,
                ),
            ),
            new StatementDrillPart(
                label: __('accounting.statements.drill.expenses'),
                sign: -1,
                reference: StatementDrillReference::accountTypeTotal(
                    AccountType::Expense,
                    DrillDateWindow::FiscalYearToPeriodEnd,
                ),
            ),
        ]);
    }

    public static function liabilitiesAndEquity(): StatementDrillTarget
    {
        return StatementDrillTarget::composite([
            new StatementDrillPart(
                label: __('accounting.statements.sections.liabilities'),
                sign: 1,
                reference: StatementDrillReference::section('liabilities'),
            ),
            new StatementDrillPart(
                label: __('accounting.statements.sections.equity'),
                sign: 1,
                reference: StatementDrillReference::section('equity'),
            ),
        ]);
    }

    public static function equityMovements(): StatementDrillTarget
    {
        return StatementDrillTarget::sectionBreakdown('equity_movements');
    }

    public static function equityClosing(): StatementDrillTarget
    {
        return StatementDrillTarget::composite([
            new StatementDrillPart(
                label: __('accounting.statements.sections.equity_opening'),
                sign: 1,
                reference: StatementDrillReference::summary('equity_opening'),
            ),
            new StatementDrillPart(
                label: __('accounting.statements.sections.equity_movements'),
                sign: 1,
                reference: StatementDrillReference::section('equity_movements'),
            ),
        ]);
    }
}
