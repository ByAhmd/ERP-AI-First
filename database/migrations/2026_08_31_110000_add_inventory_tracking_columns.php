<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The tracking columns the inventory slice keys off.
 *
 * - `products.track_inventory` — Qoyod's «يُخزن». Defaults FALSE so every
 *   existing product keeps posting exactly as before the slice: a flag that
 *   defaulted on would flip every catalogue to tracked with quantity zero,
 *   and the first sale would post cost of goods at nothing.
 *
 * - `branches.is_default` — the branch documents fall back to. Locations ARE
 *   branches in this codebase: the Branch model already declares itself the
 *   unit inventory balances attach to, and journal lines already carry
 *   branch_id. A second locations table would make one physical warehouse
 *   two unlinked rows.
 *
 * - `branch_id` on the four stock-bearing document headers. Nullable at
 *   schema level for legacy rows; the posters require it whenever a line is
 *   stocked.
 *
 * - `is_stocked` on the four item tables — the snapshot the posting keys
 *   off, written by the recalculators. A product retyped between draft and
 *   approval must not change what an existing draft posts. False on all
 *   existing rows: historical documents are never retro-inventoried.
 *
 * - `purchase_debit_notes.returns_goods` — whether goods physically went
 *   back. The sales side already carries reason_code for this distinction;
 *   the debit note needs its own flag because this codebase supports the
 *   rate-correction note (net zero, tax only), which must move no stock
 *   however many units its lines mention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('track_inventory')->default(false)->after('type');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('is_active');
        });

        // Existing companies: their oldest active branch becomes the default;
        // companies without any branch get one seeded.
        $companies = DB::table('companies')->pluck('id');

        foreach ($companies as $companyId) {
            $existing = DB::table('branches')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->first(['id']);

            if ($existing !== null) {
                DB::table('branches')->where('id', $existing->id)->update(['is_default' => true]);

                continue;
            }

            DB::table('branches')->insert([
                'id' => (string) Str::ulid(),
                'company_id' => $companyId,
                'code' => 'MAIN',
                'name' => 'المركز الرئيسي',
                'name_en' => 'Main Branch',
                'is_active' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['sales_invoices', 'sales_credit_notes', 'purchase_invoices', 'purchase_debit_notes'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->foreignUlid('branch_id')->nullable()
                    ->after('contact_id')
                    ->constrained('branches')->restrictOnDelete();
            });
        }

        foreach (['sales_invoice_items', 'sales_credit_note_items', 'purchase_invoice_items', 'purchase_debit_note_items'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->boolean('is_stocked')->default(false)->after('product_id');
            });
        }

        Schema::table('purchase_debit_notes', function (Blueprint $table): void {
            $table->boolean('returns_goods')->default(false)->after('original_invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_debit_notes', function (Blueprint $table): void {
            $table->dropColumn('returns_goods');
        });

        foreach (['sales_invoice_items', 'sales_credit_note_items', 'purchase_invoice_items', 'purchase_debit_note_items'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('is_stocked');
            });
        }

        foreach (['sales_invoices', 'sales_credit_notes', 'purchase_invoices', 'purchase_debit_notes'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropForeign(['branch_id']);
                $t->dropColumn('branch_id');
            });
        }

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('track_inventory');
        });
    }
};
