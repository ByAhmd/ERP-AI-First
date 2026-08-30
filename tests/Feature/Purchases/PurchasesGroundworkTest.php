<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\ChartOfAccountsTemplate;
use App\Services\Sales\CatalogueTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The ground the purchase documents stand on.
 *
 * Two facts every purchase poster will assume, pinned before any poster
 * exists: the supplier-advances role resolves to 1170 and is an asset, and a
 * purchasable product can name the expense account its bills debit.
 */
final class PurchasesGroundworkTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);
    }

    #[Test]
    public function the_supplier_advances_role_resolves_to_an_asset_on_a_fresh_company(): void
    {
        $account = app(AccountRegistry::class)->get(SystemAccount::SupplierAdvances);

        $this->assertSame('1170', $account->code);
        // An asset — our money held by the supplier — where the customer
        // mirror on 2180 is a liability. The payment poster's directions
        // depend on this and a flip would balance perfectly.
        $this->assertSame(AccountType::Asset, $account->type);
        $this->assertNotSame(
            $account->getKey(),
            app(AccountRegistry::class)->get(SystemAccount::CustomerAdvances)->getKey(),
        );
    }

    #[Test]
    public function rerunning_the_template_creates_nothing_and_rewrites_nothing(): void
    {
        $before = Account::query()->count();

        $created = app(ChartOfAccountsTemplate::class)->applyTo($this->company);

        $this->assertSame(0, $created);
        $this->assertSame($before, Account::query()->count());
    }

    #[Test]
    public function a_purchasable_product_stores_its_expense_account(): void
    {
        app(CatalogueTemplate::class)->applyTo($this->company);

        $cogs = app(AccountRegistry::class)->get(SystemAccount::CostOfGoodsSold);

        $product = Product::create([
            'name' => 'ورق تصوير',
            'name_en' => 'Copy Paper',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'is_purchased' => true,
            'buying_price' => '25',
            'expense_account_id' => $cogs->getKey(),
        ]);

        $this->assertSame($cogs->getKey(), $product->refresh()->expenseAccount?->getKey());
    }
}
