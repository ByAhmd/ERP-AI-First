<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock adjustments — the manual door into the stock ledger.
 *
 * Two kinds behind one document: `opening` is Qoyod's الأرصدة الافتتاحية
 * للمخزون (DR المخزون / CR حساب الرصيد الافتتاحي), and `count` is the
 * accounting core of الجرد — the counted variance, increase or decrease,
 * against an offset account.
 *
 * One deviation from Qoyod, deliberate: their stocktake demands a REVENUE
 * account for surpluses and an expense account for shortages; this document
 * takes one offset account defaulting to تسويات المخزون 5150 both ways,
 * because crediting revenue for a counting artifact inflates the income
 * statement's top line, and the keyed adjustments account exists for exactly
 * this. The account stays user-selectable, so Qoyod's exact shape remains
 * reachable.
 *
 * Lines store the signed DELTA, not Qoyod's actual-quantity input — the form
 * accepts either and converts, but a stored delta keeps the poster
 * independent of when the "current" figure was read. `unit_cost` is entered
 * and required on increases; decreases ignore it and relieve at the running
 * average resolved at approval, snapshotted into `resolved_unit_cost`.
 *
 * Approved adjustments are immutable — correction is a counter-adjustment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('kind', 20);
            $table->string('status', 20)->default(DocumentStatus::Draft->value);

            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();
            $table->date('adjustment_date');
            $table->string('description')->nullable();

            $table->foreignUlid('offset_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'stock_adjustments_company_reference_unique');
            $table->index(['company_id', 'status'], 'stock_adjustments_company_status_idx');
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('stock_adjustment_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_number');

            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();

            $table->decimal('quantity_change', 19, 4);
            $table->decimal('unit_cost', 19, 4)->nullable();

            // Resolved at approval, stored for the record.
            $table->decimal('resolved_unit_cost', 19, 4)->nullable();
            $table->decimal('value_change', 19, 4)->nullable();

            $table->timestamps();

            $table->unique(['stock_adjustment_id', 'line_number'], 'stock_adjustment_items_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
