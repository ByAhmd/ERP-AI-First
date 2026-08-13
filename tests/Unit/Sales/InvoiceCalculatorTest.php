<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Enums\DiscountType;
use App\Enums\TaxCategory;
use App\Services\Sales\Data\LineAmounts;
use App\Services\Sales\Exceptions\InvoiceRuleViolation;
use App\Services\Sales\InvoiceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The arithmetic of an invoice line.
 *
 * The figures below were derived three times independently — once against
 * ZATCA's calculation model, once as a practising accountant would read the
 * columns, and once as exact rational arithmetic — and reconciled. The three
 * agreed on every figure while disagreeing on the rules that produce them,
 * which is why the cases that separate the rules are here explicitly rather
 * than only the ones that happen to agree.
 *
 * The calculator touches no database, which is the point of having it be a
 * separate object. It does resolve translated messages when it refuses
 * something, so the application is booted — but nothing here reads or writes a
 * row.
 */
final class InvoiceCalculatorTest extends TestCase
{
    private InvoiceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new InvoiceCalculator;
    }

    /**
     * @return array<string, array{string, string, bool, string, string, string, string, string, string}>
     */
    public static function lineScenarios(): array
    {
        // qty, price, inclusive, discountType, discountValue, rate => net, tax, total
        return [
            'S1 exclusive, no discount' => ['3', '100.00', false, 'percentage', '0', '15', '300.0000', '45.0000', '345.0000'],
            'S2 inclusive, no discount' => ['3', '115.00', true, 'percentage', '0', '15', '300.0000', '45.0000', '345.0000'],
            'S3 exclusive, 10 percent' => ['2', '100.00', false, 'percentage', '10', '15', '180.0000', '27.0000', '207.0000'],
            'S4 inclusive, 10 percent' => ['2', '115.00', true, 'percentage', '10', '15', '180.0000', '27.0000', '207.0000'],
            'S5 exclusive, fixed 25' => ['4', '50.00', false, 'amount', '25', '15', '175.0000', '26.2500', '201.2500'],
            'S6 zero rated' => ['1', '100.00', false, 'percentage', '0', '0', '100.0000', '0.0000', '100.0000'],
            'S7 exclusive rounding' => ['7', '33.33', false, 'percentage', '0', '15', '233.3100', '34.9965', '268.3065'],
            'S8 inclusive rounding' => ['3', '10.00', true, 'percentage', '0', '15', '26.0870', '3.9130', '30.0000'],
        ];
    }

    #[Test]
    #[DataProvider('lineScenarios')]
    public function it_resolves_a_line(
        string $quantity,
        string $unitPrice,
        bool $inclusive,
        string $discountType,
        string $discountValue,
        string $rate,
        string $expectedNet,
        string $expectedTax,
        string $expectedTotal,
    ): void {
        $line = $this->calculator->line(
            quantity: $quantity,
            unitPrice: $unitPrice,
            isInclusive: $inclusive,
            discountType: DiscountType::from($discountType),
            discountValue: $discountValue,
            taxRate: $rate,
        );

        $this->assertSame($expectedNet, $line->netAmount);
        $this->assertSame($expectedTax, $line->taxAmount);
        $this->assertSame($expectedTotal, $line->lineTotal);
    }

    #[Test]
    public function an_inclusive_line_always_foots_to_the_price_that_was_quoted(): void
    {
        // The invariant an inclusive price has, and the one that computing tax
        // as net × rate breaks: 26.0870 × 0.15 rounds to 3.9131, which totals
        // 30.0001 — a riyal figure the customer never agreed to.
        foreach (['10.00', '33.33', '7.77', '1.01', '0.03', '999.99'] as $price) {
            foreach (['1', '3', '7', '11'] as $quantity) {
                $line = $this->calculator->line(
                    quantity: $quantity,
                    unitPrice: $price,
                    isInclusive: true,
                    discountType: DiscountType::Percentage,
                    discountValue: '0',
                    taxRate: '15',
                );

                $expected = bcmul($quantity, $price, 4);

                $this->assertSame(
                    $expected,
                    $line->lineTotal,
                    "{$quantity} × {$price} inclusive should foot to {$expected}",
                );

                // And the split must reconstruct it.
                $this->assertSame(
                    $expected,
                    bcadd($line->netAmount, $line->taxAmount, 4),
                );
            }
        }
    }

    #[Test]
    public function a_fixed_discount_on_an_inclusive_line_comes_off_the_inclusive_price(): void
    {
        // The case none of the standard scenarios exercise, and the one where a
        // naive implementation silently loses the tax portion of the discount.
        // 4 × 50.00 inclusive is 200.00; 25.00 off leaves 175.00 inclusive,
        // which is 152.1739 net and 22.8261 tax. Subtracting the gross discount
        // from the net instead would give 148.9130 — 3.26 of net simply gone.
        $line = $this->calculator->line(
            quantity: '4',
            unitPrice: '50.00',
            isInclusive: true,
            discountType: DiscountType::Amount,
            discountValue: '25',
            taxRate: '15',
        );

        $this->assertSame('152.1739', $line->netAmount);
        $this->assertSame('22.8261', $line->taxAmount);
        $this->assertSame('175.0000', $line->lineTotal);
    }

    #[Test]
    public function a_discount_reduces_the_tax_with_the_consideration(): void
    {
        // A discount at the time of supply reduces the consideration, so it
        // reduces the VAT too. Applying it after tax overstates output VAT —
        // money the company would hand to ZATCA and never collect.
        $undiscounted = $this->calculator->line('4', '50.00', false, DiscountType::Percentage, '0', '15');
        $discounted = $this->calculator->line('4', '50.00', false, DiscountType::Amount, '25', '15');

        $this->assertSame('30.0000', $undiscounted->taxAmount);
        $this->assertSame('26.2500', $discounted->taxAmount);
    }

    #[Test]
    public function a_document_totals_its_tax_per_group_rather_than_summing_its_lines(): void
    {
        // EN 16931 BR-CO-17, which ZATCA inherits. Eleven lines of 0.03 at 15%
        // each carry a tax that rounds to nothing, but the group they belong to
        // is 0.33 and owes 0.05. Summing the lines would file a return short.
        $lines = array_fill(0, 11, $this->calculator->line(
            quantity: '1',
            unitPrice: '0.03',
            isInclusive: false,
            discountType: DiscountType::Percentage,
            discountValue: '0',
            taxRate: '15',
            taxCategory: TaxCategory::Standard,
        ));

        $summed = array_reduce(
            $lines,
            static fn (string $carry, LineAmounts $line): string => bcadd($carry, $line->taxAmount, 4),
            '0.0000',
        );

        $totals = $this->calculator->document($lines, currencyScale: 2);

        $this->assertSame('0.3300', $totals->subtotalNet);
        $this->assertSame('0.0500', $totals->taxTotal);
        $this->assertSame('0.3800', $totals->total);

        // The line sum is visibly different, which is the whole point.
        $this->assertSame('0.0495', $summed);
    }

    #[Test]
    public function mixed_rates_are_grouped_separately(): void
    {
        // Applying one rate to a mixed subtotal is the classic error: 700 × 15%
        // would give 105.00 where the answer is 90.00, because a hundred of it
        // is zero-rated.
        $totals = $this->calculator->document([
            $this->calculator->line('3', '100.00', false, DiscountType::Percentage, '0', '15', TaxCategory::Standard),
            $this->calculator->line('1', '100.00', false, DiscountType::Percentage, '0', '0', TaxCategory::ZeroRated),
            $this->calculator->line('3', '115.00', true, DiscountType::Percentage, '0', '15', TaxCategory::Standard),
        ], currencyScale: 2);

        $this->assertSame('700.0000', $totals->subtotalNet);
        $this->assertSame('90.0000', $totals->taxTotal);
        $this->assertSame('790.0000', $totals->total);
        $this->assertCount(2, $totals->taxBreakdown);
    }

    #[Test]
    public function zero_rated_and_exempt_stay_apart_though_both_are_zero(): void
    {
        // They report in different boxes of the same return, so a document that
        // collapsed them into one "no tax" group could not be filed from.
        $totals = $this->calculator->document([
            $this->calculator->line('1', '100.00', false, DiscountType::Percentage, '0', '0', TaxCategory::ZeroRated),
            $this->calculator->line('1', '200.00', false, DiscountType::Percentage, '0', '0', TaxCategory::Exempt),
        ], currencyScale: 2);

        $this->assertCount(2, $totals->taxBreakdown);
        $this->assertSame('0.0000', $totals->taxTotal);
        $this->assertSame('300.0000', $totals->subtotalNet);
    }

    #[Test]
    public function a_document_total_is_always_its_net_plus_its_tax(): void
    {
        $totals = $this->calculator->document([
            $this->calculator->line('7', '33.33', false, DiscountType::Percentage, '0', '15', TaxCategory::Standard),
            $this->calculator->line('3', '10.00', true, DiscountType::Percentage, '0', '15', TaxCategory::Standard),
        ], currencyScale: 2);

        $this->assertSame(
            $totals->total,
            bcadd($totals->subtotalNet, $totals->taxTotal, 4),
        );
    }

    #[Test]
    public function a_discount_larger_than_its_line_is_refused(): void
    {
        $this->expectException(InvoiceRuleViolation::class);

        $this->calculator->line('1', '100.00', false, DiscountType::Amount, '150', '15');
    }

    #[Test]
    public function a_negative_discount_is_refused(): void
    {
        // Otherwise it is a surcharge wearing a discount's name, and it would
        // not appear as one on the invoice.
        $this->expectException(InvoiceRuleViolation::class);

        $this->calculator->line('1', '100.00', false, DiscountType::Amount, '-10', '15');
    }

    #[Test]
    public function blank_numeric_input_is_treated_as_zero(): void
    {
        // The ordinary state of a half-filled form, not an error.
        $line = $this->calculator->line('2', '50.00', false, DiscountType::Percentage, '', '15');

        $this->assertSame('100.0000', $line->netAmount);
        $this->assertSame('15.0000', $line->taxAmount);
    }
}
