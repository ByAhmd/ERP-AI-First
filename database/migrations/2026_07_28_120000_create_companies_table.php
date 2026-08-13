<?php

declare(strict_types=1);

use App\Enums\CompanyStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenancy root. Every tenant-owned table in the platform references this one.
 *
 * Address columns follow ZATCA's structured address requirement for e-invoicing
 * rather than a free-text block, because the seller address must be emitted as
 * discrete UBL 2.1 elements and cannot be reliably parsed out of prose later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Legal identity.
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('commercial_registration_no', 20)->nullable();
            $table->string('vat_registration_number', 15)->nullable();
            $table->string('group_vat_number', 15)->nullable();

            // ZATCA structured address (UBL 2.1 cac:PostalAddress).
            $table->string('building_number', 4)->nullable();
            $table->string('street_name')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 5)->nullable();
            $table->string('additional_number', 4)->nullable();
            $table->char('country_code', 2)->default('SA');

            // Contact.
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('logo_path')->nullable();

            // Financial configuration.
            $table->char('base_currency', 3)->default('SAR');
            $table->string('timezone', 64)->default('Asia/Riyadh');
            // Fiscal years frequently diverge from the Gregorian calendar year;
            // stored as month/day so a year can be generated for any period.
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->unsignedTinyInteger('fiscal_year_start_day')->default(1);
            // Zakat is assessed on the Hijri year for most Saudi entities.
            $table->boolean('uses_hijri_fiscal_year')->default(false);

            $table->string('status', 20)->default(CompanyStatus::Active->value);

            // Free-form preferences that carry no relational meaning. Anything
            // queried, reported on, or constrained gets a real column instead.
            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('vat_registration_number');
            $table->unique('commercial_registration_no');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
