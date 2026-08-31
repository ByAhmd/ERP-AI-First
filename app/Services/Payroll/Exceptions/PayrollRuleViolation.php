<?php

declare(strict_types=1);

namespace App\Services\Payroll\Exceptions;

use App\Models\Account;
use RuntimeException;

/**
 * A payroll document that cannot be accepted.
 */
final class PayrollRuleViolation extends RuntimeException
{
    public static function notDraft(): self
    {
        return new self(__('payroll.errors.not_draft'));
    }

    public static function alreadyApproved(string $reference): self
    {
        return new self(__('payroll.errors.already_approved', ['reference' => $reference]));
    }

    public static function amountNotPositive(): self
    {
        return new self(__('payroll.errors.amount_not_positive'));
    }

    public static function employeeNotPayable(string $name): self
    {
        return new self(__('payroll.errors.employee_not_payable', ['name' => $name]));
    }

    public static function paymentAccountInvalid(?Account $account): self
    {
        return new self(__('payroll.errors.payment_account_invalid', [
            'account' => $account?->displayName() ?? '—',
        ]));
    }

    public static function advanceHasRepayments(string $reference): self
    {
        return new self(__('payroll.errors.advance_has_repayments', ['reference' => $reference]));
    }

    public static function settlementExceedsRemaining(string $reference, string $remaining): self
    {
        return new self(__('payroll.errors.settlement_exceeds_remaining', [
            'reference' => $reference,
            'remaining' => number_format((float) $remaining, 2),
        ]));
    }

    public static function bonusHasAllocations(string $reference): self
    {
        return new self(__('payroll.errors.bonus_has_allocations', ['reference' => $reference]));
    }

    public static function notApproved(string $reference): self
    {
        return new self(__('payroll.errors.not_approved', ['reference' => $reference]));
    }
}
