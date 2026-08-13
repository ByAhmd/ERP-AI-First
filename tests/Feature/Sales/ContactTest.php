<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Sales\Exceptions\ContactRuleViolation;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Customers and suppliers.
 *
 * The details held here are what a tax invoice is checked against — name, VAT
 * number, national address — so the tests are about what survives being
 * reported to ZATCA rather than what the screen accepts.
 */
final class ContactTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeCompany('Acme Trading');
    }

    #[Test]
    public function a_customer_reference_is_generated_in_its_own_series(): void
    {
        // Qoyod opens its customer form on CUS001.
        $first = Contact::create(['contact_name' => 'مؤسسة النخيل']);
        $second = Contact::create(['contact_name' => 'شركة الواحة']);

        $this->assertSame('CUS001', $first->code);
        $this->assertSame('CUS002', $second->code);
    }

    #[Test]
    public function suppliers_are_numbered_separately_from_customers(): void
    {
        Contact::create(['contact_name' => 'عميل']);
        $supplier = Contact::create(['contact_name' => 'مورد', 'type' => ContactType::Supplier]);

        // Sharing a counter would make the two lists read as if records were
        // missing from each.
        $this->assertSame('VEN001', $supplier->code);
    }

    #[Test]
    public function a_reference_given_explicitly_is_kept(): void
    {
        $contact = Contact::create(['contact_name' => 'مؤسسة النخيل', 'code' => 'LEGACY-42']);

        // Migrating companies arrive with references their staff already know.
        $this->assertSame('LEGACY-42', $contact->code);
    }

    #[Test]
    public function two_contacts_cannot_share_a_reference(): void
    {
        Contact::create(['contact_name' => 'الأول', 'code' => 'DUP-1']);

        $this->expectException(UniqueConstraintViolationException::class);

        Contact::create(['contact_name' => 'الثاني', 'code' => 'DUP-1']);
    }

    #[Test]
    public function the_government_entity_flag_cannot_be_cleared_once_set(): void
    {
        // Qoyod's own help says the same. Sales to a government body are
        // reported differently, so clearing it would change how invoices
        // already raised should have been treated.
        $contact = Contact::create([
            'contact_name' => 'أمانة المنطقة',
            'is_government_entity' => true,
        ]);

        $this->expectException(ContactRuleViolation::class);

        $contact->update(['is_government_entity' => false]);
    }

    #[Test]
    public function the_government_entity_flag_can_still_be_set(): void
    {
        $contact = Contact::create(['contact_name' => 'جهة']);

        $contact->update(['is_government_entity' => true]);

        $this->assertTrue($contact->refresh()->is_government_entity);
    }

    #[Test]
    public function a_contact_is_retired_rather_than_erased(): void
    {
        $contact = Contact::create(['contact_name' => 'مؤسسة النخيل']);

        $contact->delete();

        // The invoices raised against it have to keep naming someone.
        $this->assertSoftDeleted($contact);
    }

    #[Test]
    public function only_active_contacts_are_offered_to_a_new_document(): void
    {
        Contact::create(['contact_name' => 'نشط']);
        Contact::create(['contact_name' => 'متوقف', 'status' => ContactStatus::Inactive]);

        $selectable = Contact::query()->customers()->selectable()->get();

        $this->assertCount(1, $selectable);
        $this->assertSame('نشط', $selectable->first()->contact_name);
    }

    #[Test]
    public function customers_and_suppliers_are_separable_though_they_share_a_table(): void
    {
        Contact::create(['contact_name' => 'عميل']);
        Contact::create(['contact_name' => 'مورد', 'type' => ContactType::Supplier]);

        $this->assertCount(1, Contact::query()->customers()->get());
        $this->assertCount(1, Contact::query()->suppliers()->get());
    }

    #[Test]
    public function a_national_address_is_reported_incomplete_rather_than_refused(): void
    {
        // A company must be able to record a customer before it knows the full
        // address; the invoice is where the requirement bites.
        $partial = Contact::create([
            'contact_name' => 'بدون رقم مبنى',
            'billing_address' => 'طريق الملك فهد',
            'billing_city' => 'الرياض',
            'billing_zip' => '12345',
        ]);

        $this->assertFalse($partial->hasCompleteNationalAddress());

        $partial->update(['billing_building_number' => '2743']);

        $this->assertTrue($partial->refresh()->hasCompleteNationalAddress());
    }

    #[Test]
    public function the_display_name_leads_with_the_organisation_when_there_is_one(): void
    {
        $withOrg = Contact::create([
            'contact_name' => 'أحمد',
            'organization_name' => 'مؤسسة النخيل',
        ]);

        $withoutOrg = Contact::create(['contact_name' => 'خالد']);

        $this->assertSame('مؤسسة النخيل — أحمد', $withOrg->displayName());
        $this->assertSame('خالد', $withoutOrg->displayName());
    }

    #[Test]
    public function contacts_do_not_leak_between_companies(): void
    {
        Contact::create(['contact_name' => 'عميل أكمي']);

        $rival = $this->makeOtherCompany('Globex Industrial');

        $seen = app(CompanyContext::class)->forCompany(
            $rival,
            static fn (): int => Contact::query()->count(),
        );

        $this->assertSame(0, $seen);
        $this->assertSame(1, Contact::query()->count());
    }

    #[Test]
    public function each_company_numbers_its_own_contacts_from_one(): void
    {
        Contact::create(['contact_name' => 'عميل أكمي']);

        $rival = $this->makeOtherCompany('Globex Industrial');

        $code = app(CompanyContext::class)->forCompany(
            $rival,
            static fn (): string => Contact::create(['contact_name' => 'عميل جلوبكس'])->code,
        );

        // A shared counter would leak how many customers a competitor has.
        $this->assertSame('CUS001', $code);
    }
}
