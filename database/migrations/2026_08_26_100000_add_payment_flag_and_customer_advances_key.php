<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Groundwork the receipts slice stands on.
 *
 * Two changes, both to the chart of accounts:
 *
 *  - `is_payment_account` — Qoyod's `يمكن الدفع والتحصيل بهذا الحساب` flag,
 *    seen as a column on their chart screen. It is the gate on where a receipt
 *    may deposit money. An account-type check cannot do this job: Accounts
 *    Receivable is itself an asset, and pointing a receipt at it produces a
 *    perfect wash entry — money vanishes from no account and the trial balance
 *    stays clean. A legitimate overdraft account is a liability and would be
 *    wrongly blocked. Eligibility is a declaration, not a type.
 *
 *  - The `customer_advances` system key on 2180. The template now keys new
 *    companies, but `createNode()` deliberately never touches an existing
 *    account, so companies provisioned before this slice are backfilled here —
 *    otherwise the first unallocated receipt throws SystemAccountMissing.
 *
 * Backfills match by the template's own codes and skip anything the company
 * has renamed a key onto already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table): void {
            $table->boolean('is_payment_account')->default(false)->after('is_system');
        });

        DB::statement(<<<'SQL'
            UPDATE chart_of_accounts
            SET is_payment_account = 1
            WHERE code IN ('1110', '1120')
              AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE chart_of_accounts
            SET system_key = 'customer_advances', is_system = 1
            WHERE code = '2180'
              AND system_key IS NULL
              AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table): void {
            $table->dropColumn('is_payment_account');
        });

        DB::statement(<<<'SQL'
            UPDATE chart_of_accounts
            SET system_key = NULL, is_system = 0
            WHERE system_key = 'customer_advances'
        SQL);
    }
};
