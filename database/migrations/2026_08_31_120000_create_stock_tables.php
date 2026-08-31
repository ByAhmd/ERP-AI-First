<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stock state, and its proof.
 *
 * Three tables, three jobs:
 *
 * - `product_costs` — THE lock row. One per tracked product, holding the
 *   company-wide quantity and value. `total_value` is authoritative;
 *   `average_cost` is a derived mirror for display. Every stock mutation
 *   locks this row first, which is what serializes concurrent approvals and
 *   makes the moving average computable at all.
 *
 * - `product_stocks` — quantity per product per branch. No value or average
 *   columns: cost is company-wide (Qoyod's model), so per-branch value is
 *   quantity times the company average, stated rather than stored.
 *
 * - `stock_movements` — the append-only audit stream. Its PRIMARY KEY is a
 *   bigint sequence, a deliberate deviation from the house ULID convention,
 *   because monotone application order IS the semantic: cost applies in
 *   posting order (running-forward, Qoyod's behavior), while movement_date
 *   carries the document date, and storing both is what makes a backdated
 *   document's effect diagnosable instead of mysterious. Each movement
 *   snapshots the unit cost and value it was applied at, plus the running
 *   balances after it — the GL's 1140 balance must equal the sum of
 *   `value_after` across products' latest movements, and the invariant test
 *   holds that tie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_costs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();

            $table->decimal('quantity_on_hand', 19, 4)->default(0);
            $table->decimal('total_value', 19, 4)->default(0);
            $table->decimal('average_cost', 19, 4)->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'product_id'], 'product_costs_company_product_unique');
        });

        Schema::create('product_stocks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();

            $table->decimal('quantity_on_hand', 19, 4)->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'branch_id'], 'product_stocks_unique');
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();

            // The document's issue date — the ledger's date, never the
            // supply date.
            $table->date('movement_date');

            $table->string('source_type');
            $table->ulid('source_id');

            // Null only for zero-value movements, which emit no ledger lines.
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('value', 19, 4);

            $table->decimal('branch_qty_after', 19, 4);
            $table->decimal('qty_after', 19, 4);
            $table->decimal('value_after', 19, 4);

            $table->timestamps();

            $table->index(['company_id', 'product_id', 'id'], 'stock_movements_product_idx');
            $table->index(['company_id', 'product_id', 'branch_id'], 'stock_movements_branch_idx');
            $table->index(['source_type', 'source_id'], 'stock_movements_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('product_costs');
    }
};
