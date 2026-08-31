<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\TaxCategory;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationItem;
use App\Models\Tax;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\QuotationConverter;
use App\Services\Sales\Reports\QuotationAging;
use App\Services\Sales\SalesQuotationApprover;
use App\Services\Sales\SalesQuotationRecalculator;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The quotation aging report.
 *
 * The whitelist is the report: only approved quotations count, and the trap
 * this file pins is the converted quotation — its approved_at survives
 * conversion, so any filter looser than the status whitelist counts the
 * quote and its invoice both.
 */
final class QuotationAgingReportTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->customer = Contact::create(['contact_name' => 'مؤسسة النخيل']);
    }

    #[Test]
    public function only_approved_quotations_count(): void
    {
        // A draft, an approved, a cancelled, and a converted one.
        $this->draftQuotation('100.00', CarbonImmutable::parse('2026-06-01'));

        $this->approvedQuotation('250.00', CarbonImmutable::parse('2026-06-05'));

        $cancelled = $this->approvedQuotation('400.00', CarbonImmutable::parse('2026-06-08'));
        app(SalesQuotationApprover::class)->cancel($cancelled);

        $converted = $this->approvedQuotation('800.00', CarbonImmutable::parse('2026-06-10'));
        app(QuotationConverter::class)->convert($converted);

        $report = app(QuotationAging::class)->build(CarbonImmutable::parse('2026-06-30'));

        // Only the open approved quote: the converted one is out even though
        // its approved_at is still set.
        $this->assertSame('250.0000', $report->totals[0]['amount']);
        $this->assertSame(1, $report->totals[0]['count']);
    }

    #[Test]
    public function the_issue_date_drives_visibility_not_the_creation_date(): void
    {
        // Created today, issued tomorrow — invisible today.
        $this->approvedQuotation('250.00', CarbonImmutable::parse('2026-06-21'));

        $service = app(QuotationAging::class);

        $this->assertSame([], $service->build(CarbonImmutable::parse('2026-06-20'))->rows);
        $this->assertCount(1, $service->build(CarbonImmutable::parse('2026-06-21'))->rows);
    }

    #[Test]
    public function expired_but_approved_quotations_stay_in(): void
    {
        $quotation = $this->approvedQuotation(
            '250.00',
            CarbonImmutable::parse('2026-05-01'),
            expiry: CarbonImmutable::parse('2026-05-15'),
        );

        $this->assertTrue($quotation->refresh()->isExpired());

        $report = app(QuotationAging::class)->build(CarbonImmutable::parse('2026-06-30'));

        // Lapsed, not resolved: the pipeline still holds it.
        $this->assertSame('250.0000', $report->totals[0]['amount']);
    }

    // ------------------------------------------------------------------ helpers

    private function approvedQuotation(
        string $grossTotal,
        CarbonImmutable $issueDate,
        ?CarbonImmutable $expiry = null,
    ): SalesQuotation {
        return app(SalesQuotationApprover::class)->approve(
            $this->draftQuotation($grossTotal, $issueDate, $expiry),
        );
    }

    private function draftQuotation(
        string $grossTotal,
        CarbonImmutable $issueDate,
        ?CarbonImmutable $expiry = null,
    ): SalesQuotation {
        $quotation = SalesQuotation::create([
            'reference' => app(SalesQuotationApprover::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'issue_date' => $issueDate,
            'expiry_date' => $expiry ?? $issueDate->addDays(30),
        ]);

        SalesQuotationItem::create([
            'sales_quotation_id' => $quotation->getKey(),
            'product_name' => 'خدمة',
            'quantity' => '1',
            'unit_price' => $grossTotal,
            'is_inclusive' => true,
            'tax_id' => Tax::query()->where('category', TaxCategory::ZeroRated)->value('id'),
        ]);

        return app(SalesQuotationRecalculator::class)->recalculate($quotation->refresh());
    }
}
