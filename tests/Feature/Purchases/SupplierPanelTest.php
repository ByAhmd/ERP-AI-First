<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Enums\ContactType;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The supplier screens.
 *
 * One contact model serves two resources, so the thing these tests guard is
 * the wall between them: a supplier must never surface on the customer side,
 * a customer must never be reachable through a supplier route, and each side
 * must number from its own series.
 */
final class SupplierPanelTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Acme Trading');
        $this->admin = $this->makeAdministrator($this->company, 'admin@acme.test');

        $this->actingInPanel($this->admin, $this->company);
    }

    #[Test]
    public function creating_a_supplier_stamps_the_type_and_numbers_from_the_supplier_series(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSupplier::class)
            ->fillForm(['contact_name' => 'شركة التوريدات الأولى'])
            ->call('create')
            ->assertHasNoFormErrors();

        $supplier = Contact::query()->firstOrFail();

        $this->assertSame(ContactType::Supplier, $supplier->type);
        // Its own per-type counter — the customer series stays at CUS001.
        $this->assertSame('VEN001', $supplier->code);
    }

    #[Test]
    public function each_side_numbers_independently(): void
    {
        Contact::create(['contact_name' => 'عميل', 'type' => ContactType::Customer]);
        Contact::create(['contact_name' => 'مورد', 'type' => ContactType::Supplier]);

        $this->assertSame('CUS001', Contact::query()->customers()->firstOrFail()->code);
        $this->assertSame('VEN001', Contact::query()->suppliers()->firstOrFail()->code);
    }

    #[Test]
    public function suppliers_do_not_surface_on_the_customer_list_nor_customers_on_the_supplier_list(): void
    {
        Contact::create(['contact_name' => 'عميل النخيل', 'type' => ContactType::Customer]);
        Contact::create(['contact_name' => 'مورد التوريدات', 'type' => ContactType::Supplier]);

        Livewire::actingAs($this->admin)
            ->test(ListSuppliers::class)
            ->assertCanSeeTableRecords(Contact::query()->suppliers()->get())
            ->assertCanNotSeeTableRecords(Contact::query()->customers()->get());

        Livewire::actingAs($this->admin)
            ->test(ListCustomers::class)
            ->assertCanSeeTableRecords(Contact::query()->customers()->get())
            ->assertCanNotSeeTableRecords(Contact::query()->suppliers()->get());
    }

    #[Test]
    public function a_customer_cannot_be_reached_through_the_supplier_edit_route(): void
    {
        $customer = Contact::create(['contact_name' => 'عميل النخيل', 'type' => ContactType::Customer]);

        // The base-query filter, not the table, is what blocks this: pasting a
        // customer id into the supplier route must find nothing.
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->admin)
            ->test(EditSupplier::class, ['record' => $customer->getKey()]);
    }
}
