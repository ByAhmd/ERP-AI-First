<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales invoices.
 *
 * Field for field from Qoyod's own create form. Two of its header fields are
 * ZATCA requirements rather than conveniences: `supply_date`, which is when the
 * goods were supplied and is reported separately from the issue date; and the
 * tax figures, which must be stated per line.
 *
 * Everything a line needs to explain itself is stored on the line, not
 * recomputed. A rate can change, a product can be re-priced, and a tax can be
 * renamed — an invoice raised in March must still report March. So the line
 * keeps the price, the resolved net, the rate, the category and the tax amount
 * it was actually raised with. The tax id remains for provenance, but nothing
 * reads back through it to produce a figure.
 *
 * Currency columns are here from the outset even though foreign-currency
 * posting is a later slice. Adding them once documents exist would mean
 * revisiting every one of them; adding them now costs a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('status', 20)->default(DocumentStatus::Draft->value);

            $table->foreignUlid('contact_id')->constrained('contacts')->restrictOnDelete();

            $table->date('issue_date');
            $table->date('due_date');
            // When the supply actually happened. ZATCA reports it separately
            // from the issue date, and they genuinely differ when an invoice is
            // raised after delivery.
            $table->date('supply_date');

            $table->string('description')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->text('notes')->nullable();

            // Totals, held rather than derived: a list of invoices reads them
            // for every row, and re-aggregating lines per row is the difference
            // between a page that returns and one that does not. Written by the
            // service inside the same transaction as the lines.
            $table->decimal('subtotal_net', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);

            // Null means the company's base currency at par.
            $table->foreignUlid('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('exchange_rate', 19, 6)->nullable();

            // The ledger entry this invoice produced when approved. Restricted:
            // the entry is the accounting record and must not vanish while the
            // document still points at it.
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'sales_invoices_company_reference_unique');
            $table->index(['company_id', 'status'], 'sales_invoices_company_status_idx');
            $table->index(['company_id', 'issue_date'], 'sales_invoices_company_issued_idx');
            // Read by a customer statement and by the aging report.
            $table->index(['company_id', 'contact_id', 'status'], 'sales_invoices_contact_status_idx');
        });

        Schema::create('sales_invoice_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sales_invoice_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_number');

            $table->foreignUlid('product_id')->nullable()->constrained('products')->restrictOnDelete();
            // Copied from the product at the time, then editable. What was
            // billed must survive the product being renamed.
            $table->string('product_name');
            $table->text('product_description')->nullable();
            $table->string('unit_name', 40)->nullable();

            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            // Whether unit_price already contains the tax. Qoyod's `شامل؟`.
            $table->boolean('is_inclusive')->default(false);

            $table->decimal('discount_value', 19, 4)->default(0);
            $table->string('discount_type', 20)->default(DiscountType::Percentage->value);
            // The money the discount actually removed, resolved at entry.
            $table->decimal('discount_amount', 19, 4)->default(0);

            $table->foreignUlid('tax_id')->nullable()->constrained('taxes')->restrictOnDelete();
            // Snapshots. The rate and category are what this line was raised
            // at; changing the tax record later must not restate the invoice.
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->string('tax_category', 1)->nullable();

            $table->decimal('net_amount', 19, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4)->default(0);

            $table->timestamps();

            $table->unique(['sales_invoice_id', 'line_number'], 'sales_invoice_items_line_unique');
            $table->index(['company_id', 'product_id'], 'sales_invoice_items_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_items');
        Schema::dropIfExists('sales_invoices');
    }
};
