<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase invoices — فواتير المشتريات.
 *
 * The mirror of `sales_invoices`, and a separate table for the same reason
 * quotations were: every consumer of that table reads it as receivables, and
 * a bill in a receivable query is a customer statement naming a debt that
 * does not exist.
 *
 * One table serves both kinds of bill — the standard فاتورة مشتريات and the
 * فاتورة بسيطة — split by `kind` the way `contacts` splits customers from
 * suppliers. This deliberately inverts the quotation's separate-table
 * argument, and the inversion is the point: a quotation must never appear in
 * a receivable query, but a simple bill must appear in EVERY payable query —
 * outstanding, the voucher's allocation picker, the supplier statement. With
 * separate tables each of those is a UNION someone forgets; with one table,
 * forgetting the filter produces correct numbers.
 *
 * The deliberate omissions carry the buy-side design:
 *
 * - No `subtype`. KSA-2 classifies documents the seller issues; recording a
 *   classification on a document we merely received would be meaningless data
 *   waiting to be misread. (Self-billed invoices — KSA-2 flag 7, where the
 *   buyer does issue — are the one future door, and it is a column away.)
 * - No `supply_date`. Supply-date reporting is a seller obligation.
 * - `issue_date` is OUR recognition date and drives the ledger. The
 *   supplier's paper date lives in `supplier_invoice_date`, so a January
 *   bill keyed in March posts in March without either falsifying the record
 *   or colliding with a closed period.
 *
 * `supplier_invoice_number` deviates from Qoyod, which overloads its single
 * reference field: keying the same paper bill twice doubles expense, input
 * VAT and payables while balancing perfectly, and duplicate detection is
 * impossible when the supplier's number is optional free text inside our own
 * sequence. The composite unique makes the double-key a database error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('kind', 20)->default('standard');
            $table->string('status', 20)->default(DocumentStatus::Draft->value);

            $table->foreignUlid('contact_id')->constrained('contacts')->restrictOnDelete();

            // The supplier's own document identity.
            $table->string('supplier_invoice_number', 100)->nullable();
            $table->date('supplier_invoice_date')->nullable();

            $table->date('issue_date');
            // Nullable at schema level: simple bills carry none. The poster
            // requires it for standard bills.
            $table->date('due_date')->nullable();

            $table->string('description')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->text('notes')->nullable();

            $table->decimal('subtotal_net', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);

            $table->foreignUlid('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('exchange_rate', 19, 6)->nullable();

            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'purchase_invoices_company_reference_unique');
            // MySQL ignores NULL rows here, so bills without a supplier
            // number coexist freely; two bills with the same one refuse.
            $table->unique(
                ['company_id', 'contact_id', 'supplier_invoice_number'],
                'purchase_invoices_supplier_number_unique',
            );
            $table->index(['company_id', 'status'], 'purchase_invoices_company_status_idx');
            $table->index(['company_id', 'issue_date'], 'purchase_invoices_company_issued_idx');
            // Read by a supplier statement and by the payables aging report.
            $table->index(['company_id', 'contact_id', 'status'], 'purchase_invoices_contact_status_idx');
        });

        Schema::create('purchase_invoice_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_invoice_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_number');

            $table->foreignUlid('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('product_name');
            $table->text('product_description')->nullable();
            $table->string('unit_name', 40)->nullable();

            // The debit side of the line, snapshotted at entry: copied from
            // the product on pick, editable, and the only way a bill reaches
            // the right expense account. One company-level default here would
            // silently fold rent into cost of goods sold and make gross
            // margin fiction.
            $table->foreignUlid('expense_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->boolean('is_inclusive')->default(false);

            $table->decimal('discount_value', 19, 4)->default(0);
            $table->string('discount_type', 20)->default(DiscountType::Percentage->value);
            $table->decimal('discount_amount', 19, 4)->default(0);

            $table->foreignUlid('tax_id')->nullable()->constrained('taxes')->restrictOnDelete();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->string('tax_category', 1)->nullable();

            $table->decimal('net_amount', 19, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4)->default(0);

            $table->timestamps();

            $table->unique(['purchase_invoice_id', 'line_number'], 'purchase_invoice_items_line_unique');
            $table->index(['company_id', 'product_id'], 'purchase_invoice_items_product_idx');
            $table->index(['company_id', 'expense_account_id'], 'purchase_invoice_items_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
        Schema::dropIfExists('purchase_invoices');
    }
};
