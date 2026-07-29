<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The general ledger.
 *
 * Every financial movement in the platform ends up here. Invoices, payments,
 * payroll and depreciation all post journal entries; nothing writes balances
 * directly. That is what makes the trial balance authoritative and what lets
 * the VAT return be derived from the ledger rather than from documents.
 *
 * There are no soft deletes. A posted entry is never removed — correction is by
 * reversal, which leaves both the original and the correction visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('number', 40);
            $table->date('entry_date');
            $table->string('description')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('status', 20)->default(JournalEntryStatus::Draft->value);

            $table->foreignUlid('fiscal_year_id')->nullable()
                ->constrained()->restrictOnDelete();
            $table->foreignUlid('accounting_period_id')->nullable()
                ->constrained()->restrictOnDelete();

            // Cached totals. Both sides are stored because a trial balance reads
            // them for every entry in a range, and re-aggregating the lines each
            // time is the difference between a report that returns and one that
            // does not. Kept truthful by the posting service inside the same
            // transaction that writes the lines.
            $table->decimal('total_debit', 19, 4)->default(0);
            $table->decimal('total_credit', 19, 4)->default(0);

            // What produced this entry: an invoice, a payment, a payroll run.
            // Nullable morph because manual entries have no source document.
            $table->string('source_type')->nullable();
            $table->ulid('source_id')->nullable();

            // Reversal pairing. `reverses_id` points from the correcting entry
            // to the original — the direction the predecessor had inverted,
            // where the original pointed at its own reversal.
            $table->foreignUlid('reverses_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamp('posted_at')->nullable();
            $table->foreignUlid('posted_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'entry_date']);
            $table->index(['company_id', 'status']);
            $table->index(['source_type', 'source_id']);
            // An original may be reversed only once.
            $table->unique('reverses_id');
        });

        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('account_id')
                ->constrained('chart_of_accounts')
                // An account with history cannot be deleted.
                ->restrictOnDelete();

            $table->unsignedSmallInteger('line_number');
            $table->string('description')->nullable();

            // Base-currency amounts. Exactly one of the two is non-zero; the
            // pair is used rather than a signed amount because that is how
            // ledgers are read, reported and audited.
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);

            // Document-currency amounts, present only for foreign-currency
            // lines. The base amounts above always govern the ledger.
            $table->foreignUlid('currency_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->decimal('foreign_debit', 19, 4)->nullable();
            $table->decimal('foreign_credit', 19, 4)->nullable();
            $table->decimal('exchange_rate', 19, 6)->nullable();

            // Reporting dimensions.
            $table->foreignUlid('branch_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('cost_center_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->timestamps();

            $table->unique(['journal_entry_id', 'line_number']);
            // The general ledger query: one account, ordered by date.
            $table->index(['company_id', 'account_id']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['company_id', 'cost_center_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
    }
};
