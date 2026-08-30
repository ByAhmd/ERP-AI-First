<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Accounting\AccountRegistry;

/**
 * Accounts the platform posts to by role rather than by code.
 *
 * Anything the application decides to debit or credit on the user's behalf —
 * VAT, cost of sales, the year-end transfer — is named here. Modules resolve
 * these through {@see AccountRegistry} instead of
 * hard-coding account codes, which is what tied the predecessor's invoicing to
 * one particular numbering scheme.
 */
enum SystemAccount: string
{
    // Receivables and payables control accounts. Sub-ledger detail lives on the
    // journal line's contact; these carry the aggregate.
    case AccountsReceivable = 'accounts_receivable';
    case AccountsPayable = 'accounts_payable';

    // VAT. Output is what the company owes on sales; input is what it may
    // recover on purchases. The return is the difference between the two, read
    // from the ledger.
    case VatOutputPayable = 'vat_output_payable';
    case VatInputRecoverable = 'vat_input_recoverable';

    // Money received from a customer and not yet applied to any invoice. A
    // liability: it is theirs until an allocation turns it into settlement.
    // The platform chooses this account when a receipt is not fully
    // allocated, which is exactly the by-role criterion of this enum.
    case CustomerAdvances = 'customer_advances';

    case WithholdingTaxPayable = 'withholding_tax_payable';
    case ZakatPayable = 'zakat_payable';

    // The charges a Saudi income statement reports below the operating result:
    // financing cost, income tax and zakat. Grouped under one account because
    // the statement needs a subtotal above them — "صافي الدخل قبل الفوائد
    // والضريبة والزكاة" — and anything the company files beneath this account
    // is classified with them automatically.
    case InterestTaxAndZakat = 'interest_tax_and_zakat';

    // Where an approved sales document credits its net revenue. Qoyod carries
    // no revenue account on a product or its category, posting instead to one
    // company-level default; this is that default, resolved by role so a
    // company may renumber its chart without breaking invoicing.
    case SalesRevenue = 'sales_revenue';

    // Inventory and cost of sales, posted when stock moves.
    case Inventory = 'inventory';
    case CostOfGoodsSold = 'cost_of_goods_sold';
    case InventoryAdjustment = 'inventory_adjustment';

    // Equity. Closing a fiscal year moves the year's result into retained
    // earnings; the suspense account catches an unbalanced opening balance
    // rather than letting it silently distort equity.
    case RetainedEarnings = 'retained_earnings';
    case CurrentYearResult = 'current_year_result';
    case OpeningBalanceSuspense = 'opening_balance_suspense';

    // Foreign exchange differences arising on settlement and revaluation.
    case ExchangeGain = 'exchange_gain';
    case ExchangeLoss = 'exchange_loss';

    // Rounding differences, which are unavoidable once tax is calculated per
    // line and totals are presented to two decimals.
    case RoundingDifference = 'rounding_difference';

    public function label(): string
    {
        return __("accounting.system_account.{$this->value}");
    }
}
