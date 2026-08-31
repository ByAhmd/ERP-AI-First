<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fixed-asset register and its subledger.
 *
 * Five tables, five jobs:
 *
 * - `fixed_asset_types` — تصنيفات الأصول. Carries the three accounts every
 *   posting resolves through: cost, accumulated depreciation and expense.
 *   The keyed system accounts are only this table's form defaults.
 *
 * - `fixed_assets` — the register, one row per registered asset. Deliberately
 *   holds NO accumulated or book-value column: accumulated-to-date is the
 *   opening figure plus the sum of posted charges, derived on read — a stored
 *   mirror is exactly the kind of figure that drifts from the ledger.
 *
 * - `depreciation_runs` — الإهلاك document headers. Runs post immediately
 *   (never draft-then-post), so a run's entry can never sit editable on the
 *   ledger screen.
 *
 * - `depreciation_charges` — the stored subledger, one row per asset per
 *   period, POSTED rows only; the forward schedule is a display projection.
 *   The unique (asset, period-of-record) index is THE idempotency anchor:
 *   running the same period twice inserts nothing and aborts before any money
 *   moves. `posted_period_id` records where the money actually landed, which
 *   differs from the period of record when a catch-up crosses a closed
 *   period. Keyed to period ids, never a 'YYYY-MM' string, because periods
 *   follow the company's fiscal start day and may straddle calendar months.
 *
 * - `fixed_asset_disposals` — الاستبعادات, sale and scrap. Snapshots what the
 *   disposal entry actually removed (cost, posted accumulated, gain/loss),
 *   never a recomputation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('description')->nullable();

            $table->foreignUlid('asset_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignUlid('accumulated_depreciation_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignUlid('depreciation_expense_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->unsignedInteger('default_useful_life_months')->nullable();
            $table->boolean('is_depreciable')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name'], 'fixed_asset_types_company_name_unique');
        });

        Schema::create('fixed_assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('fixed_asset_type_id')->constrained()->restrictOnDelete();

            $table->string('reference', 40);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('description')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('barcode')->nullable();

            // Every depreciation line carries the asset's branch; without it,
            // branch income statements omit depreciation while branch balance
            // sheets carry the assets.
            $table->foreignUlid('branch_id')->constrained()->restrictOnDelete();

            $table->string('status', 20);
            $table->string('acquisition_kind', 20);

            $table->date('acquisition_date');
            // Qoyod's تاريخ الاستلام — the day depreciation starts.
            $table->date('in_service_date');

            $table->decimal('cost', 19, 4);
            $table->decimal('salvage_value', 19, 4)->default(0);
            $table->unsignedInteger('useful_life_months')->nullable();
            $table->boolean('is_depreciable');

            $table->decimal('opening_accumulated_depreciation', 19, 4)->default(0);
            // Qoyod's تاريخ آخر إهلاك: the engine charges only days after it.
            $table->date('opening_depreciated_through')->nullable();

            $table->foreignUlid('acquisition_journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            // Reserved for the from-bill capitalization slice.
            $table->foreignUlid('purchase_invoice_item_id')->nullable()
                ->constrained('purchase_invoice_items')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'fixed_assets_company_reference_unique');
            $table->index(['company_id', 'status'], 'fixed_assets_company_status_idx');
            $table->index(['company_id', 'fixed_asset_type_id'], 'fixed_assets_company_type_idx');
        });

        Schema::create('depreciation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);

            // Null filter means جميع التصنيفات / all assets.
            $table->foreignUlid('fixed_asset_type_id')->nullable()
                ->constrained()->restrictOnDelete();
            $table->foreignUlid('fixed_asset_id')->nullable()
                ->constrained()->restrictOnDelete();

            $table->foreignUlid('through_period_id')
                ->constrained('accounting_periods')->restrictOnDelete();
            $table->date('through_date');

            $table->string('status', 20)->default(DocumentStatus::Approved->value);

            // Written a moment after the header inside the same transaction —
            // the charge rows must exist before the entry posts, and they
            // reference the run.
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUlid('reversal_journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->decimal('total_amount', 19, 4);
            $table->unsignedInteger('assets_count');

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'depreciation_runs_company_reference_unique');
        });

        Schema::create('depreciation_charges', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('fixed_asset_id')->constrained()->restrictOnDelete();

            // The period OF RECORD — the month the charge belongs to.
            $table->foreignUlid('accounting_period_id')
                ->constrained('accounting_periods')->restrictOnDelete();
            // The period the money actually landed in; differs on catch-up
            // past a closed period.
            $table->foreignUlid('posted_period_id')
                ->constrained('accounting_periods')->restrictOnDelete();

            $table->foreignUlid('depreciation_run_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->decimal('amount', 19, 4);
            $table->unsignedSmallInteger('days');

            $table->timestamps();

            $table->unique(['fixed_asset_id', 'accounting_period_id'], 'depreciation_charges_asset_period_unique');
            $table->index(['company_id', 'fixed_asset_id', 'id'], 'depreciation_charges_asset_idx');
        });

        Schema::create('fixed_asset_disposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('kind', 20);
            $table->string('status', 20)->default(DocumentStatus::Draft->value);

            $table->foreignUlid('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->date('disposal_date');

            // Sale only: net proceeds, VAT, and where the money arrived.
            $table->decimal('proceeds', 19, 4)->nullable();
            $table->foreignUlid('tax_id')->nullable()->constrained('taxes')->restrictOnDelete();
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->foreignUlid('proceeds_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();

            // Snapshots of what the disposal entry actually removed — read
            // from posted figures, never recomputed.
            $table->decimal('gain_loss_amount', 19, 4)->nullable();
            $table->decimal('cost_removed', 19, 4)->nullable();
            $table->decimal('accumulated_removed', 19, 4)->nullable();

            $table->foreignUlid('catchup_run_id')->nullable()
                ->constrained('depreciation_runs')->restrictOnDelete();
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->string('notes')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'fixed_asset_disposals_company_reference_unique');
            $table->index(['company_id', 'status'], 'fixed_asset_disposals_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_disposals');
        Schema::dropIfExists('depreciation_charges');
        Schema::dropIfExists('depreciation_runs');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_types');
    }
};
