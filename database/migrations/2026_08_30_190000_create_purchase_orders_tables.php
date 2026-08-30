<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders — أوامر الشراء.
 *
 * The quotation's shape on the buy side: a commercial document with the
 * bill's line arithmetic and none of its accounting. Qoyod confirms an
 * order posts nothing at any status — it is إداري — and the structure
 * makes that unviolable:
 *
 * - No `journal_entry_id`. The absent column is the guard.
 * - No `due_date`, no `supply_date`, no `subtype` — nothing is owed,
 *   supplied or classified until the bill exists.
 * - `expiry_date` is required: the order's validity, and the source of the
 *   derived متأخرة state. Qoyod has no separate delivery-date field.
 * - No expense account on the lines: the debit side is a property of the
 *   bill, resolved when the order converts.
 *
 * The conversion link lives on `purchase_invoices.purchase_order_id` with a
 * unique index — one order, one bill, decided by the database, exactly as
 * the quotation's link works on the sales side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('status', 20)->default(PurchaseOrderStatus::Draft->value);

            $table->foreignUlid('contact_id')->constrained('contacts')->restrictOnDelete();

            $table->date('issue_date');
            // How long the order stands — تاريخ الانتهاء. Past it without
            // billing, the order reads as متأخرة, derived from the clock.
            $table->date('expiry_date');

            $table->string('description')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->text('notes')->nullable();

            $table->decimal('subtotal_net', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);

            $table->foreignUlid('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('exchange_rate', 19, 6)->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'purchase_orders_company_reference_unique');
            $table->index(['company_id', 'status'], 'purchase_orders_company_status_idx');
            $table->index(['company_id', 'issue_date'], 'purchase_orders_company_issued_idx');
            // Read by the purchase-order aging report.
            $table->index(['company_id', 'contact_id', 'status'], 'purchase_orders_contact_status_idx');
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_order_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_number');

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

            $table->foreignUlid('tax_id')->nullable()->constrained('taxes')->restrictOnDelete();
            // Snapshots of what was ordered. Conversion does not read them:
            // the bill re-resolves rate and category at its own date.
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->string('tax_category', 1)->nullable();

            $table->decimal('net_amount', 19, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4)->default(0);

            $table->timestamps();

            $table->unique(['purchase_order_id', 'line_number'], 'purchase_order_items_line_unique');
            $table->index(['company_id', 'product_id'], 'purchase_order_items_product_idx');
        });

        // One order → at most one bill, ever; the database decides the race
        // between two clerks converting at once. Provenance-only, never read
        // to produce a figure.
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->foreignUlid('purchase_order_id')->nullable()
                ->after('contact_id')
                ->constrained('purchase_orders')->restrictOnDelete();

            $table->unique(['purchase_order_id'], 'purchase_invoices_order_unique');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->dropForeign(['purchase_order_id']);
            $table->dropUnique('purchase_invoices_order_unique');
            $table->dropColumn('purchase_order_id');
        });

        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
