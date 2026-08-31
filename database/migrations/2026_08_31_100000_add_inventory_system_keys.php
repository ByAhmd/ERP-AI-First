<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The system keys the inventory slice stands on.
 *
 * The template already keys المخزون 1140، تكلفة البضاعة المباعة 5100 and
 * تسويات المخزون 5150 for new companies, but `createNode()` deliberately
 * never touches an existing account — the customer-advances and
 * supplier-advances slices hit the same trap and left the same kind of
 * backfill. Without it, the first stocked approval on a pre-existing tenant
 * throws SystemAccountMissing.
 *
 * Matches by the template's own codes and skips anything a key was already
 * renamed onto.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            '1140' => 'inventory',
            '5100' => 'cost_of_goods_sold',
            '5150' => 'inventory_adjustment',
        ] as $code => $key) {
            DB::statement(<<<SQL
                UPDATE chart_of_accounts
                SET system_key = '{$key}', is_system = 1
                WHERE code = '{$code}'
                  AND system_key IS NULL
                  AND deleted_at IS NULL
            SQL);
        }
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE chart_of_accounts
            SET system_key = NULL, is_system = 0
            WHERE system_key IN ('inventory', 'cost_of_goods_sold', 'inventory_adjustment')
        SQL);
    }
};
