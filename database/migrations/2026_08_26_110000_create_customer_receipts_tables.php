<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer receipts — سند قبض — and their allocations to invoices.
 *
 * The allocation table is what makes payment status derivable at all: journal
 * lines carry no contact, so "how much of invoice X is paid" can only be
 * answered from documents. No payment status is stored anywhere — an invoice's
 * state is computed from these rows joined to *approved* receipts, so an
 * abandoned draft affects nothing.
 *
 * `journal_entry_id` on the allocation row is null when the allocation was
 * part of the receipt's own approval entry, and set when it records a later
 * movement of an advance onto an invoice — a second accounting event with its
 * own date and its own entry, because re-opening the receipt's original entry
 * would restate the period the money arrived in.
 *
 * Deliberately not built: the supplier mirror, customer refunds, FX
 * settlement (currency columns nullable now so it never forces a rebuild),
 * and the bounced-cheque workflow — a bounce is a future event document dated
 * the bounce day, never a void.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('status', 20)->default(DocumentStatus::Draft->value);

            $table->foreignUlid('contact_id')->constrained('contacts')->restrictOnDelete();

            // Where the money lands. User-chosen, gated by is_payment_account —
            // an account-type check cannot do this job, because receivable is
            // itself an asset and pointing a receipt at it is a perfect wash.
            $table->foreignUlid('deposit_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->date('receipt_date');

            // Kept so a cheque is identifiable when the bounce workflow lands.
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_reference', 100)->nullable();

            $table->decimal('amount', 19, 4)->default(0);

            $table->string('description')->nullable();
            $table->text('notes')->nullable();

            $table->foreignUlid('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('exchange_rate', 19, 6)->nullable();

            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'customer_receipts_company_reference_unique');
            $table->index(['company_id', 'status'], 'customer_receipts_company_status_idx');
            $table->index(['company_id', 'receipt_date'], 'customer_receipts_company_date_idx');
            // The customer statement and advances-balance path.
            $table->index(['company_id', 'contact_id', 'status'], 'customer_receipts_contact_status_idx');
        });

        Schema::create('customer_receipt_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_receipt_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('sales_invoice_id')
                ->constrained('sales_invoices')->restrictOnDelete();

            $table->unsignedSmallInteger('line_number');
            $table->decimal('amount', 19, 4);

            // Null: settled inside the receipt's approval entry. Set: the
            // advances-to-receivable movement entry of a later allocation.
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['customer_receipt_id', 'line_number'], 'receipt_allocations_line_unique');
            // One row per invoice per receipt. Changing an allocation is
            // unlink-then-relink, as it is in Qoyod.
            $table->unique(['customer_receipt_id', 'sales_invoice_id'], 'receipt_allocations_invoice_unique');
            // The "amount paid on invoice X" read path.
            $table->index(['company_id', 'sales_invoice_id'], 'receipt_allocations_company_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipt_allocations');
        Schema::dropIfExists('customer_receipts');
    }
};
