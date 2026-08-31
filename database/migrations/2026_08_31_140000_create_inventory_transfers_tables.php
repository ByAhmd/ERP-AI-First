<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory transfers — نقل المخزون.
 *
 * Goods moving between branches. In this codebase every branch posts to the
 * one المخزون account, so a completed transfer moves NO money — quantities
 * change hands at the company-wide average and the ledger's net effect is
 * zero, which is exactly Qoyod's own net when both locations share the
 * default inventory account. The absence of a journal_entry_id column is
 * therefore deliberate, the quotation's argument again: no poster can be
 * pointed at a document that has nothing to post.
 *
 * Deliberately deferred with the per-location inventory accounts that give
 * them meaning: Qoyod's إرسال-only intermediate state and its حساب النقل
 * المؤقت (the in-transit account value stages through). This document is
 * Qoyod's one-step إرسال واستقبال; the two-step ships when locations carry
 * their own accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('status', 20)->default(DocumentStatus::Draft->value);

            $table->foreignUlid('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUlid('to_branch_id')->constrained('branches')->restrictOnDelete();

            $table->date('transfer_date');
            $table->string('description')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'inventory_transfers_company_reference_unique');
            $table->index(['company_id', 'status'], 'inventory_transfers_company_status_idx');
            $table->index(['company_id', 'transfer_date'], 'inventory_transfers_company_date_idx');
        });

        Schema::create('inventory_transfer_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('inventory_transfer_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_number');

            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 19, 4);

            $table->timestamps();

            $table->unique(['inventory_transfer_id', 'line_number'], 'inventory_transfer_items_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_items');
        Schema::dropIfExists('inventory_transfers');
    }
};
