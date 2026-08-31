<?php

declare(strict_types=1);

namespace App\Services\Payroll\Exceptions;

use RuntimeException;

/**
 * An employee payment voucher that cannot be approved.
 */
final class VoucherRejected extends RuntimeException
{
    public static function notDraft(): self
    {
        return new self(__('payroll.errors.voucher_not_draft'));
    }

    public static function alreadyApproved(string $reference): self
    {
        return new self(__('payroll.errors.already_approved', ['reference' => $reference]));
    }

    public static function amountNotPositive(): self
    {
        return new self(__('payroll.errors.amount_not_positive'));
    }

    public static function targetInvalid(): self
    {
        return new self(__('payroll.errors.allocation_target_invalid'));
    }

    public static function crossEmployee(): self
    {
        return new self(__('payroll.errors.allocation_cross_employee'));
    }

    public static function targetNotApproved(): self
    {
        return new self(__('payroll.errors.allocation_target_not_approved'));
    }

    public static function exceedsOutstanding(string $label, string $outstanding): self
    {
        return new self(__('payroll.errors.allocation_exceeds_outstanding', [
            'target' => $label,
            'outstanding' => number_format((float) $outstanding, 2),
        ]));
    }

    public static function notFullyAllocated(): self
    {
        return new self(__('payroll.errors.voucher_not_fully_allocated'));
    }
}
