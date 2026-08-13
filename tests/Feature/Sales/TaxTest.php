<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Company;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Sales\Exceptions\TaxRuleViolation;
use App\Services\Sales\TaxTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * VAT rates.
 *
 * A rate is the difference between an invoice that can be filed and one that
 * cannot, so the rules here are about what ZATCA will accept rather than what
 * the screen will allow. The category in particular carries meaning the rate
 * cannot: zero-rated and exempt are both 0% and are reported differently.
 */
final class TaxTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Acme Trading');
        $this->makeChartOfAccounts($this->company);

        app(TaxTemplate::class)->applyTo($this->company);
    }

    #[Test]
    public function the_template_seeds_the_three_rates_a_saudi_company_needs(): void
    {
        // The same three Qoyod provisions, with ZATCA's category codes.
        $this->assertSame(3, Tax::query()->count());

        foreach ([TaxCategory::Standard, TaxCategory::ZeroRated, TaxCategory::Exempt] as $category) {
            $this->assertTrue(
                Tax::query()->where('category', $category)->exists(),
                "Missing the {$category->value} rate.",
            );
        }

        $standard = Tax::query()->where('category', TaxCategory::Standard)->firstOrFail();

        $this->assertSame('15.0000', $standard->rate);
        $this->assertTrue($standard->is_default);
        $this->assertTrue($standard->is_system);
    }

    #[Test]
    public function every_seeded_rate_posts_to_the_vat_liability(): void
    {
        $vat = app(AccountRegistry::class)->get(SystemAccount::VatOutputPayable);

        foreach (Tax::query()->get() as $tax) {
            $this->assertSame($vat->getKey(), $tax->account_id);
        }
    }

    #[Test]
    public function the_template_is_idempotent(): void
    {
        $created = app(TaxTemplate::class)->applyTo($this->company);

        $this->assertSame(0, $created);
        $this->assertSame(3, Tax::query()->count());
    }

    #[Test]
    public function reapplying_does_not_reset_a_changed_rate(): void
    {
        // Saudi VAT moved from 5% to 15% inside the lifetime of a working set
        // of books. A company that has adjusted its rate must not have it put
        // back by a re-run.
        $standard = Tax::query()->where('category', TaxCategory::Standard)->firstOrFail();
        $standard->update(['rate' => '5', 'name' => 'ضريبة القيمة المضافة 5%']);

        app(TaxTemplate::class)->applyTo($this->company);

        $standard->refresh();

        $this->assertSame('5.0000', $standard->rate);
        $this->assertSame('ضريبة القيمة المضافة 5%', $standard->name);
    }

    #[Test]
    public function a_zero_rated_tax_cannot_carry_a_rate(): void
    {
        $zero = Tax::query()->where('category', TaxCategory::ZeroRated)->firstOrFail();

        // It would be charged to a customer and reported to ZATCA.
        $this->expectException(TaxRuleViolation::class);

        $zero->update(['rate' => '15']);
    }

    #[Test]
    public function an_exempt_tax_cannot_carry_a_rate(): void
    {
        $this->expectException(TaxRuleViolation::class);

        Tax::create([
            'name' => 'معفاة بنسبة',
            'category' => TaxCategory::Exempt,
            'rate' => '5',
            'account_id' => app(AccountRegistry::class)->get(SystemAccount::VatOutputPayable)->getKey(),
        ]);
    }

    #[Test]
    public function a_seeded_rate_cannot_be_deleted(): void
    {
        // Documents resolve rates by category. Deleting one leaves the company
        // unable to invoice that kind of supply at all.
        $this->expectException(TaxRuleViolation::class);

        Tax::query()->where('category', TaxCategory::Exempt)->firstOrFail()->delete();
    }

    #[Test]
    public function only_one_rate_is_the_default_at_a_time(): void
    {
        $zero = Tax::query()->where('category', TaxCategory::ZeroRated)->firstOrFail();
        $zero->update(['is_default' => true]);

        $this->assertSame(1, Tax::query()->where('is_default', true)->count());
        $this->assertTrue($zero->refresh()->is_default);
    }

    #[Test]
    public function the_rate_is_offered_as_a_fraction_so_callers_never_divide(): void
    {
        // The place a rounding error would otherwise enter the ledger.
        $standard = Tax::query()->where('category', TaxCategory::Standard)->firstOrFail();

        $this->assertSame('0.150000', $standard->fraction());
        $this->assertSame('15%', $standard->formattedRate());
    }

    #[Test]
    public function rates_do_not_leak_between_companies(): void
    {
        $rival = $this->makeOtherCompany('Globex Industrial');
        $this->makeChartOfAccounts($rival);
        app(TaxTemplate::class)->applyTo($rival);

        // Still only this company's three.
        $this->assertSame(3, Tax::query()->count());
    }
}
