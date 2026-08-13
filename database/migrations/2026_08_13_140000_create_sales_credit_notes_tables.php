<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales credit notes.
 *
 * The sales invoice with its sides exchanged, plus what makes a credit note a
 * credit note. Five of those additions are regulatory rather than convenient:
 *
 *  - `original_invoice_number` is what a credit note is required to carry, and
 *    it is not the same thing as `parent_id`. ZATCA permits crediting an
 *    invoice raised on paper or before the company was on any system, so the
 *    link may be absent while the reference never is. It is free text for the
 *    same reason: a note may credit a range, "IRN 001–100, 1 Jan–31 Mar".
 *  - `original_invoice_date` is a separate field and cannot be derived when the
 *    original is external.
 *  - `event_date` is the date of the triggering event under Article 40(1). The
 *    fifteen-day window runs from the end of that date's month, so it cannot be
 *    computed from the issue date.
 *  - `reason_code` and `reason_text` are both required. The regulation
 *    recognises four circumstances and the text prints on the note itself.
 *
 * There is deliberately **no `supply_date`**: the invoice requires one, Qoyod's
 * credit note form has none, and nothing requires it here.
 *
 * Amounts are positive throughout. A credit note is not a negative invoice —
 * its direction is carried by its document type, and encoding it as negative
 * numbers would make every report special-case it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_credit_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('status', 20)->default(DocumentStatus::Draft->value);

            $table->foreignUlid('contact_id')->constrained('contacts')->restrictOnDelete();

            // The invoice being credited, when it is one this platform holds.
            $table->foreignUlid('parent_id')->nullable()
                ->constrained('sales_invoices')->restrictOnDelete();

            // Always present, even when parent_id is not.
            $table->string('original_invoice_number', 100);
            $table->date('original_invoice_date')->nullable();

            $table->date('issue_date');
            $table->date('due_date');
            $table->date('event_date');

            $table->string('reason_code', 40);
            $table->text('reason_text');

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

            $table->unique(['company_id', 'reference'], 'sales_credit_notes_company_reference_unique');
            $table->index(['company_id', 'status'], 'sales_credit_notes_company_status_idx');
            $table->index(['company_id', 'issue_date'], 'sales_credit_notes_company_issued_idx');
            // How much of an invoice is still creditable. Read on the approval
            // path and once per row on any invoice list that shows it.
            $table->index(['company_id', 'parent_id', 'status'], 'sales_credit_notes_parent_status_idx');
        });

        Schema::create('sales_credit_note_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sales_credit_note_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_number');

            // Which invoice line is being credited. Without it the only
            // possible check is on the header total, which would happily allow
            // three units credited at triple the price they were billed at.
            // Null only when there is no parent invoice to point into.
            $table->foreignUlid('sales_invoice_item_id')->nullable()
                ->constrained('sales_invoice_items')->restrictOnDelete();

            $table->foreignUlid('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('product_name');
            $table->text('product_description')->nullable();
            $table->string('unit_name', 40)->nullable();

            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->boolean('is_inclusive')->default(false);

            $table->decimal('discount_value', 19, 4)->default(0);
            $table->string('discount_type', 20)->default(DiscountType::Percentage->value);
            $table->decimal('discount_amount', 19, 4)->default(0);

            // Copied from the invoice line, never re-resolved from the tax
            // record. Crediting a 2019 invoice raised at 5% must return 5%,
            // not whatever the rate happens to be today.
            $table->foreignUlid('tax_id')->nullable()->constrained('taxes')->restrictOnDelete();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->string('tax_category', 1)->nullable();

            $table->decimal('net_amount', 19, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4)->default(0);

            $table->timestamps();

            $table->unique(['sales_credit_note_id', 'line_number'], 'sales_credit_note_items_line_unique');
            $table->index(['company_id', 'sales_invoice_item_id'], 'sales_credit_note_items_invoice_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_credit_note_items');
        Schema::dropIfExists('sales_credit_notes');
    }
};
