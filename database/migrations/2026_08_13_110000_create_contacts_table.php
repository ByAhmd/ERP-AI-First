<?php

declare(strict_types=1);

use App\Enums\ContactStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers and suppliers.
 *
 * One table for both, as Qoyod does — its customer and supplier menu items both
 * open `/tenant/contacts`. The fields are identical, and a company that both
 * buys from and sells to the same party should not maintain its tax number in
 * two places.
 *
 * Addresses and bank details are columns rather than related tables. Qoyod
 * models them as nested singular records, and singular is the point: a contact
 * has one billing address and one shipping address, so a join would buy nothing
 * but a join. If a company ever needs several delivery addresses, that is a
 * real change with a real reason behind it rather than speculation now.
 *
 * `billing_building_number` exists only on the billing side, which mirrors
 * Qoyod and is not an oversight: the building number is part of the Saudi
 * national address that a tax invoice must carry, and it is the billing
 * address that appears on one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20);
            $table->string('code', 40);

            $table->string('contact_name');
            $table->string('organization_name')->nullable();

            $table->string('primary_contact_number', 40)->nullable();
            $table->string('secondary_contact_number', 40)->nullable();
            $table->string('primary_email')->nullable();
            $table->string('secondary_email')->nullable();
            $table->string('website')->nullable();

            // The VAT registration number printed on a tax invoice. Fifteen
            // digits in Saudi Arabia, but stored as a string because a foreign
            // customer's is not.
            $table->string('tax_number', 50)->nullable();

            $table->string('status', 20)->default(ContactStatus::Active->value);

            // The currency documents for this contact default to. Nullable
            // means the company's own base currency.
            $table->foreignUlid('currency_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->boolean('is_pos')->default(false);
            // Qoyod's own help states this cannot be changed once set; a
            // government buyer changes how an invoice must be reported.
            $table->boolean('is_government_entity')->default(false);

            $table->string('billing_address')->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('billing_state', 100)->nullable();
            $table->string('billing_zip', 20)->nullable();
            $table->string('billing_building_number', 20)->nullable();
            $table->char('billing_country', 2)->nullable();

            $table->string('shipping_address')->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_zip', 20)->nullable();
            $table->char('shipping_country', 2)->nullable();

            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->char('bank_country', 2)->nullable();
            $table->char('bank_currency', 3)->nullable();
            $table->string('bank_iban', 40)->nullable();
            $table->string('bank_account_number', 40)->nullable();
            $table->string('bank_swift_code', 20)->nullable();
            $table->string('bank_address')->nullable();

            $table->timestamps();
            // Retired rather than removed: an invoice has to keep naming
            // someone, so a traded-with party is never really deletable.
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'contacts_company_code_unique');
            $table->index(['company_id', 'type'], 'contacts_company_type_idx');
            // Read by every document form, which lists active contacts of one
            // type ordered by name.
            $table->index(['company_id', 'type', 'status'], 'contacts_company_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
