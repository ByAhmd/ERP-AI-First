<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stable handle for accounts the platform posts to by name.
 *
 * VAT postings, year-end close and cost-of-sales all need to find a specific
 * account. Looking them up by code is fragile: companies renumber their charts,
 * and the predecessor hard-coded codes like '1200' and '2100' throughout its
 * invoicing logic, so any company using a different numbering silently posted to
 * the wrong account — or failed at approval time with a message about a missing
 * code.
 *
 * The key is set by the platform and never by the user, who remains free to
 * renumber and rename the account it points at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table): void {
            $table->string('system_key', 60)->nullable()->after('is_system');

            // One account per role per company.
            $table->unique(['company_id', 'system_key']);
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'system_key']);
            $table->dropColumn('system_key');
        });
    }
};
