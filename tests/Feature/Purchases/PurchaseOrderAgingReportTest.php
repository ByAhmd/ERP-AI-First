<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Enums\ContactType;
use App\Enums\TaxCategory;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Tax;
use App\Services\Purchases\PurchaseOrderApprover;
use App\Services\Purchases\PurchaseOrderConverter;
use App\Services\Purchases\PurchaseOrderRecalculator;
use App\Services\Purchases\Reports\PurchaseOrderAging;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The purchase order aging report — the quotation report's mirror.
 */
final class PurchaseOrderAgingReportTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private Contact $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->supplier = Contact::create([
            'contact_name' => 'شركة التوريدات الأولى',
            'type' => ContactType::Supplier,
        ]);
    }

    #[Test]
    public function only_approved_unbilled_orders_count(): void
    {
        $this->draftOrder('100.00', CarbonImmutable::parse('2026-06-01'));

        $this->approvedOrder('250.00', CarbonImmutable::parse('2026-06-05'));

        $billed = $this->approvedOrder('800.00', CarbonImmutable::parse('2026-06-10'));
        app(PurchaseOrderConverter::class)->convert($billed);

        $report = app(PurchaseOrderAging::class)->build(CarbonImmutable::parse('2026-06-30'));

        // The billed order is out even though its approved_at survives.
        $this->assertSame('250.0000', $report->totals[0]['amount']);
        $this->assertSame(1, $report->totals[0]['count']);
        $this->assertSame('شركة التوريدات الأولى', $report->rows[0]->name);
    }

    #[Test]
    public function the_issue_date_drives_visibility(): void
    {
        $this->approvedOrder('250.00', CarbonImmutable::parse('2026-06-21'));

        $service = app(PurchaseOrderAging::class);

        $this->assertSame([], $service->build(CarbonImmutable::parse('2026-06-20'))->rows);
        $this->assertCount(1, $service->build(CarbonImmutable::parse('2026-06-21'))->rows);
    }

    // ------------------------------------------------------------------ helpers

    private function approvedOrder(string $grossTotal, CarbonImmutable $issueDate): PurchaseOrder
    {
        return app(PurchaseOrderApprover::class)->approve(
            $this->draftOrder($grossTotal, $issueDate),
        );
    }

    private function draftOrder(string $grossTotal, CarbonImmutable $issueDate): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'reference' => app(PurchaseOrderApprover::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => $issueDate,
            'expiry_date' => $issueDate->addDays(30),
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->getKey(),
            'product_name' => 'توريد',
            'quantity' => '1',
            'unit_price' => $grossTotal,
            'is_inclusive' => true,
            'tax_id' => Tax::query()->where('category', TaxCategory::ZeroRated)->value('id'),
        ]);

        return app(PurchaseOrderRecalculator::class)->recalculate($order->refresh());
    }
}
