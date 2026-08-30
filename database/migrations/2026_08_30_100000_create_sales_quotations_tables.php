<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Enums\QuotationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales quotations — عروض الأسعار.
 *
 * A quotation is a commercial document, never an accounting one. Qoyod states
 * it verbatim: its quotation reporting is "تجاري وتحليلي، وليس محاسبي". The
 * table is shaped accordingly, and the omissions carry the design:
 *
 * - No `journal_entry_id`. The absent column is the guard — no poster can be
 *   pointed at this table by accident, at any status.
 * - No `supply_date`: nothing is supplied. No `due_date`: nothing is owed.
 *   `expiry_date` is not a renamed due date; it bounds the offer's validity
 *   and is required, as it is in Qoyod's own API.
 * - No `subtype`: KSA-2 classifies invoices. The subtype is resolved fresh at
 *   conversion, because the customer may have VAT-registered since quoting.
 * - No pointer to the invoice. The link lives on `sales_invoices.quotation_id`
 *   where a unique index makes double conversion a database impossibility.
 *
 * A separate table rather than a `document_type` column on `sales_invoices`,
 * because every existing consumer of that table — outstanding balances, the
 * receipt picker, the credit-note picker, the statement queries — reads it
 * unfiltered, and one missed type filter would put an unaccepted quote on a
 * customer's statement as a receivable.
 *
 * The lines keep the full snapshot columns even though the document never
 * posts: the printed quotation must keep saying what it said after a product
 * rename or a tax re-rate. The snapshots here are what was quoted; conversion
 * never copies the derived figures forward — it carries the raw inputs and
 * lets the invoice recalculator resolve them at the invoice's own date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quotations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('status', 20)->default(QuotationStatus::Draft->value);

            $table->foreignUlid('contact_id')->constrained('contacts')->restrictOnDelete();

            $table->date('issue_date');
            // How long the offer stands — تاريخ الانتهاء. Validity, not payment.
            $table->date('expiry_date');

            $table->string('description')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->text('notes')->nullable();

            // Totals, held rather than derived, written by the recalculator in
            // the same transaction as the lines — the invoice's convention.
            $table->decimal('subtotal_net', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);

            // Null means the company's base currency at par.
            $table->foreignUlid('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('exchange_rate', 19, 6)->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'sales_quotations_company_reference_unique');
            $table->index(['company_id', 'status'], 'sales_quotations_company_status_idx');
            $table->index(['company_id', 'issue_date'], 'sales_quotations_company_issued_idx');
            // Read by the quotation aging report, which keys on the issue date
            // over approved and not-yet-invoiced rows.
            $table->index(['company_id', 'contact_id', 'status'], 'sales_quotations_contact_status_idx');
        });

        Schema::create('sales_quotation_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sales_quotation_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_number');

            $table->foreignUlid('product_id')->nullable()->constrained('products')->restrictOnDelete();
            // Copied from the product at the time, then editable. What was
            // quoted must survive the product being renamed.
            $table->string('product_name');
            $table->text('product_description')->nullable();
            $table->string('unit_name', 40)->nullable();

            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            // Whether unit_price already contains the tax. Qoyod's `شامل؟`.
            $table->boolean('is_inclusive')->default(false);

            $table->decimal('discount_value', 19, 4)->default(0);
            $table->string('discount_type', 20)->default(DiscountType::Percentage->value);
            $table->decimal('discount_amount', 19, 4)->default(0);

            $table->foreignUlid('tax_id')->nullable()->constrained('taxes')->restrictOnDelete();
            // Snapshots of what was quoted. Conversion does not read them: the
            // invoice re-resolves rate and category from tax_id at its own
            // issue date, because the quotation's March rate is not June law.
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->string('tax_category', 1)->nullable();

            $table->decimal('net_amount', 19, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4)->default(0);

            $table->timestamps();

            $table->unique(['sales_quotation_id', 'line_number'], 'sales_quotation_items_line_unique');
            $table->index(['company_id', 'product_id'], 'sales_quotation_items_product_idx');
        });

        // The conversion link, on the invoice side. Unique: one quotation
        // yields at most one invoice, ever — Qoyod has no partial invoicing,
        // and the database rather than the service decides the race between
        // two clerks converting at once. Provenance-only, same rule as a
        // line's tax_id: kept for the audit trail, never read to produce a
        // figure or a display name.
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->foreignUlid('quotation_id')->nullable()
                ->after('contact_id')
                ->constrained('sales_quotations')->restrictOnDelete();

            $table->unique(['quotation_id'], 'sales_invoices_quotation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropForeign(['quotation_id']);
            $table->dropUnique('sales_invoices_quotation_unique');
            $table->dropColumn('quotation_id');
        });

        Schema::dropIfExists('sales_quotation_items');
        Schema::dropIfExists('sales_quotations');
    }
};
