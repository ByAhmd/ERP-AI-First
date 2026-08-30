<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier payment vouchers — سند صرف — and their allocations to bills.
 *
 * Created one slice ahead of the payment screens on purpose: BillOutstanding
 * must be three-term from its first commit — total, less debit notes, less
 * payment allocations — and the third term needs this table to join to, even
 * while it sums zero. Shipping a two-term interim is the exact bug the sales
 * side documented and fixed: a fully-paid bill fully debited on top drives a
 * supplier's payable negative inside a control account that cannot show it.
 *
 * The mirror of `customer_receipts`, with the advance on the other side of
 * the balance sheet: money paid and not yet allocated is OUR money held by
 * the supplier — an asset on 1170 — where a customer's unallocated receipt
 * is theirs, a liability on 2180.
 *
 * Deliberately not built: the received kind (a supplier refunding us),
 * multi-currency settlement, and Qoyod's cash-refund path on debit notes —
 * tracked gaps, mirroring the receipts migration's own list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('status', 20)->default(DocumentStatus::Draft->value);

            $table->foreignUlid('contact_id')->constrained('contacts')->restrictOnDelete();

            // Where the money leaves from. Gated by is_payment_account, the
            // same flag the deposit side uses — Qoyod's one flag serves both
            // directions.
            $table->foreignUlid('payment_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->date('payment_date');

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

            $table->unique(['company_id', 'reference'], 'supplier_payments_company_reference_unique');
            $table->index(['company_id', 'status'], 'supplier_payments_company_status_idx');
            $table->index(['company_id', 'payment_date'], 'supplier_payments_company_date_idx');
            $table->index(['company_id', 'contact_id', 'status'], 'supplier_payments_contact_status_idx');
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('supplier_payment_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_invoice_id')
                ->constrained('purchase_invoices')->restrictOnDelete();

            $table->unsignedSmallInteger('line_number');
            $table->decimal('amount', 19, 4);

            // Null: settled inside the voucher's approval entry. Set: the
            // advance-to-payable movement entry of a later allocation.
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['supplier_payment_id', 'line_number'], 'payment_allocations_line_unique');
            // One row per bill per voucher; changing means unlink-then-relink.
            $table->unique(['supplier_payment_id', 'purchase_invoice_id'], 'payment_allocations_invoice_unique');
            // The "amount paid on bill X" read path.
            $table->index(['company_id', 'purchase_invoice_id'], 'payment_allocations_company_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
    }
};
