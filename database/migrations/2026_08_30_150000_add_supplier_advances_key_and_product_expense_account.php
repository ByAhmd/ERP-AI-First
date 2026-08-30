<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Groundwork the purchases slices stand on.
 *
 * Two changes:
 *
 *  - The `supplier_advances` system key on 1170. The mirror image of the
 *    customer advances on 2180, but an asset: money paid to a supplier and
 *    not yet applied to any bill is ours until allocation. The template now
 *    keys new companies, but `createNode()` deliberately never touches an
 *    existing account, so companies provisioned before this slice are
 *    backfilled here — otherwise the first partially-allocated payment
 *    voucher throws SystemAccountMissing.
 *
 *  - `products.expense_account_id` — where buying this product lands in the
 *    ledger. Qoyod carries exactly this on the product (required when the
 *    product is purchasable), and the income statement is why one company
 *    default cannot do the job: rent must reach its own expense account and
 *    goods for resale must not all land in cost of goods sold, or gross
 *    margin is silently fiction. Nullable, because sale-only products never
 *    need one; the bill form falls back to cost of goods sold.
 *
 * The backfill matches by the template's own code and skips anything the
 * company has renamed a key onto already.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE chart_of_accounts
            SET system_key = 'supplier_advances', is_system = 1
            WHERE code = '1170'
              AND system_key IS NULL
              AND deleted_at IS NULL
        SQL);

        Schema::table('products', function (Blueprint $table): void {
            // Restricted: an account a product points at must not vanish
            // while the product still names it as its expense home.
            $table->foreignUlid('expense_account_id')->nullable()
                ->after('tax_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['expense_account_id']);
            $table->dropColumn('expense_account_id');
        });

        DB::statement(<<<'SQL'
            UPDATE chart_of_accounts
            SET system_key = NULL, is_system = 0
            WHERE system_key = 'supplier_advances'
        SQL);
    }
};
