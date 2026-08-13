<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\DiscountType;
use App\Enums\TaxCategory;
use App\Services\Sales\Data\DocumentTotals;
use App\Services\Sales\Data\LineAmounts;
use App\Services\Sales\Exceptions\InvoiceRuleViolation;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * The arithmetic of a sales document.
 *
 * Pure: it touches no database and knows nothing about invoices. That is
 * deliberate — this is the part that must be right, and it is far easier to be
 * sure of when it can be exercised as a table of numbers.
 *
 * Four rules, each of which is wrong in a way that is invisible until it is
 * expensive:
 *
 *  - **Intermediates are exact rationals.** 30.00 / 1.15 is held as 600/23 and
 *    rounded once, at the point a figure is stored. Rounding a unit price and
 *    then multiplying by the quantity multiplies the error by the quantity.
 *
 *  - **A discount applies in the basis the price was quoted in, before the tax
 *    split.** A discount given at the time of supply reduces the consideration,
 *    so it reduces the tax with it. Taking it off after tax overstates output
 *    VAT — and taking a gross discount off a net figure loses the tax portion
 *    of the discount entirely.
 *
 *  - **On a tax-inclusive line the tax is the difference, never the product.**
 *    net = round(gross / (1 + r)), then tax = gross − net. Computing
 *    round(net × r) instead breaks the one invariant an inclusive price has:
 *    that the customer is billed exactly the figure they were quoted. Three
 *    units at 10.00 inclusive comes to 30.0001 the wrong way.
 *
 *  - **A document's tax is recomputed per tax group, not summed from its
 *    lines.** EN 16931 BR-CO-17, which ZATCA inherits: the tax for a
 *    (category, rate) group is that group's net times the rate. Summing line
 *    tax instead fails on small lines — eleven lines of 0.03 at 15% sum to
 *    nothing while the group calculation yields 0.05.
 *
 * The last two look contradictory and are not: the group calculation happens at
 * the currency's minor unit, where an inclusive line's rounded net multiplied
 * by the rate lands back on the quoted total. Applying it at the ledger's
 * scale instead is what breaks the invariant.
 */
final class InvoiceCalculator
{
    /**
     * The scale everything is stored at, matching the ledger's columns.
     */
    public const int SCALE = 4;

    /**
     * Resolve one line.
     *
     * @param  string  $taxRate  A percentage, e.g. "15" — not a fraction.
     */
    public function line(
        string $quantity,
        string $unitPrice,
        bool $isInclusive,
        DiscountType $discountType,
        string $discountValue,
        string $taxRate,
        ?TaxCategory $taxCategory = null,
    ): LineAmounts {
        $base = BigRational::of($this->sane($quantity))
            ->multipliedBy($this->sane($unitPrice));

        $discount = $this->discount($base, $discountType, $discountValue);

        if ($discount->isGreaterThan($base)) {
            throw InvoiceRuleViolation::discountExceedsLine();
        }

        $afterDiscount = $base->minus($discount);
        $rate = BigRational::of($this->sane($taxRate))->dividedBy(100);

        if ($isInclusive) {
            // The entered figure already contains the tax, so it is the total
            // and must survive untouched. The net is derived from it and the
            // tax is whatever is left — which is what guarantees the line foots
            // to the price the customer was quoted.
            $gross = $this->round($afterDiscount);
            $net = $this->round($afterDiscount->dividedBy($rate->plus(1)));
            $tax = $this->round(BigRational::of($gross)->minus($net));

            return new LineAmounts(
                netAmount: $net,
                taxAmount: $tax,
                lineTotal: $gross,
                discountAmount: $this->round($discount),
                taxRate: $this->round($taxRate),
                taxCategory: $taxCategory,
                isInclusive: true,
            );
        }

        $net = $this->round($afterDiscount);
        $tax = $this->round($afterDiscount->multipliedBy($rate));

        return new LineAmounts(
            netAmount: $net,
            taxAmount: $tax,
            lineTotal: $this->round(BigRational::of($net)->plus($tax)),
            discountAmount: $this->round($discount),
            taxRate: $this->round($taxRate),
            taxCategory: $taxCategory,
            isInclusive: false,
        );
    }

    /**
     * Total a document's lines.
     *
     * @param  list<LineAmounts>  $lines
     * @param  int  $currencyScale  The currency's minor unit — 2 for SAR.
     */
    public function document(array $lines, int $currencyScale = 2): DocumentTotals
    {
        $subtotal = BigRational::zero();
        $discountTotal = BigRational::zero();

        /** @var array<string, array{category: ?string, rate: string, net: BigRational}> $groups */
        $groups = [];

        foreach ($lines as $line) {
            // Projected to the currency's own precision first. Every consumer —
            // the printed invoice, the ledger, the return — has to agree, and
            // they only do if they all start from the same projection.
            $net = BigRational::of($line->netAmount)->toScale($currencyScale, RoundingMode::HalfUp);

            $subtotal = $subtotal->plus($net);
            $discountTotal = $discountTotal->plus(
                BigRational::of($line->discountAmount)->toScale($currencyScale, RoundingMode::HalfUp),
            );

            $key = $line->taxGroup();

            $groups[$key] ??= [
                'category' => $line->taxCategory?->value,
                'rate' => $line->taxRate,
                'net' => BigRational::zero(),
            ];

            $groups[$key]['net'] = $groups[$key]['net']->plus($net);
        }

        $taxTotal = BigRational::zero();
        $breakdown = [];

        foreach ($groups as $key => $group) {
            $rate = BigRational::of($group['rate'])->dividedBy(100);

            // BR-CO-17: the group's tax is its net times the rate, rounded
            // once. Not the sum of the line taxes inside it.
            $groupTax = $group['net']
                ->multipliedBy($rate)
                ->toScale($currencyScale, RoundingMode::HalfUp);

            $taxTotal = $taxTotal->plus($groupTax);

            $breakdown[$key] = [
                'category' => $group['category'],
                'rate' => $group['rate'],
                'net' => $this->round($group['net']),
                'tax' => $this->round($groupTax),
            ];
        }

        return new DocumentTotals(
            subtotalNet: $this->round($subtotal),
            discountTotal: $this->round($discountTotal),
            taxTotal: $this->round($taxTotal),
            total: $this->round($subtotal->plus($taxTotal)),
            taxBreakdown: $breakdown,
        );
    }

    private function discount(BigRational $base, DiscountType $type, string $value): BigRational
    {
        $value = BigRational::of($this->sane($value));

        if ($value->isNegative()) {
            throw InvoiceRuleViolation::negativeDiscount();
        }

        return $type === DiscountType::Percentage
            ? $base->multipliedBy($value)->dividedBy(100)
            : $value;
    }

    /**
     * Half-up away from zero, so a credit note is the exact negation of the
     * invoice it reverses and leaves no stray halala behind.
     */
    private function round(BigNumber|string $value): string
    {
        return (string) BigRational::of($value)->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    /**
     * BigRational rejects an empty string, and a blank numeric input is the
     * ordinary state of a half-filled form rather than an error.
     */
    private function sane(string $value): string
    {
        return trim($value) === '' ? '0' : trim($value);
    }
}
