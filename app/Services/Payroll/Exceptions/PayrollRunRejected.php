<?php

declare(strict_types=1);

namespace App\Services\Payroll\Exceptions;

use RuntimeException;

/**
 * A payroll run that cannot proceed.
 */
final class PayrollRunRejected extends RuntimeException
{
    public static function notDraft(): self
    {
        return new self(__('payroll.errors.run_not_draft'));
    }

    public static function alreadyApproved(string $reference): self
    {
        return new self(__('payroll.errors.already_approved', ['reference' => $reference]));
    }

    public static function nothingToPay(): self
    {
        return new self(__('payroll.errors.nothing_to_pay'));
    }

    public static function netBelowZero(string $employee): self
    {
        return new self(__('payroll.errors.net_below_zero', ['employee' => $employee]));
    }

    public static function notApproved(string $reference): self
    {
        return new self(__('payroll.errors.run_not_approved', ['reference' => $reference]));
    }

    public static function payslipsAllocated(string $reference): self
    {
        return new self(__('payroll.errors.payslips_allocated', ['reference' => $reference]));
    }
}
