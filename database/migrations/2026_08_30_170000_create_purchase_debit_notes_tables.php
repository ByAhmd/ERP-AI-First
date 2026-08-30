<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase debit notes — الإشعارات المدينة.
 *
 * The buy-side mirror of the sales credit note: the document that reduces
 * what we owe a supplier, for returned goods or a correction in our favour.
 *
 * The deliberate omissions vs the sales credit note, and why:
 *
 * - No `reason_code` / `reason_text`. ZATCA's Article 40 reasons bind
 *   documents we issue as a seller; Qoyod's purchase debit notes carry none.
 * - No `event_date`. The 15-day issuance window is a seller obligation.
 * - No `subtype`, no `due_date` — the same seller-only fields the bill
 *   itself omits.
 *
 * `original_invoice_number` is required and carries the SUPPLIER's invoice
 * number — copied from the parent bill's `supplier_invoice_number` when the
 * bill lives in this system, free text when it lives in a predecessor
 * (Qoyod's مرجع خارجي). The ledger narration quotes it, because the person
 * reconciling a supplier statement holds the supplier's numbers, not ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_debit_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('status', 20)->default(DocumentStatus::Draft->value);

            $table->foreignUlid('contact_id')->constrained('contacts')->restrictOnDelete();

            // The bill being corrected, when it is one this platform holds.
            // Restricted: a bill a note points at must not vanish.
            $table->foreignUlid('parent_id')->nullable()
                ->constrained('purchase_invoices')->restrictOnDelete();

            $table->string('original_invoice_number', 100);
            $table->date('original_invoice_date')->nullable();

            $table->date('issue_date');

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

            $table->unique(['company_id', 'reference'], 'purchase_debit_notes_company_reference_unique');
            $table->index(['company_id', 'status'], 'purchase_debit_notes_company_status_idx');
            // The "how much of bill X is debited" read path.
            $table->index(['company_id', 'parent_id', 'status'], 'purchase_debit_notes_parent_status_idx');
        });

        Schema::create('purchase_debit_note_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_debit_note_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_number');

            // The bill line being corrected — the anchor that lets the rate
            // snapshot come from what was actually billed.
            $table->foreignUlid('purchase_invoice_item_id')->nullable()
                ->constrained('purchase_invoice_items')->restrictOnDelete();

            $table->foreignUlid('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('product_name');
            $table->text('product_description')->nullable();
            $table->string('unit_name', 40)->nullable();

            // Copied from the bill line when anchored; the correction must
            // relieve the account the cost landed in, not a default.
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

            $table->unique(['purchase_debit_note_id', 'line_number'], 'purchase_debit_note_items_line_unique');
            $table->index(['company_id', 'product_id'], 'purchase_debit_note_items_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_debit_note_items');
        Schema::dropIfExists('purchase_debit_notes');
    }
};
