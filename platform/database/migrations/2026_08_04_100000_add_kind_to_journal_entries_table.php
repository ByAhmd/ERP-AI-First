<?php

declare(strict_types=1);

use App\Enums\JournalEntryKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Classify journal entries.
 *
 * Opening balances need to be found again — to be revised while still a draft,
 * and to be refused a second time once posted. Nothing on the entry identified
 * them: the date belongs to the fiscal year rather than to the entry's purpose,
 * the description is the user's to rewrite, and the source morph is for the
 * document that produced an entry, which an opening balance does not have.
 *
 * A classification rather than a boolean, because the ledger already
 * contemplates a second kind the platform does not yet post — the year-end
 * transfer to retained earnings — and a second boolean would be the point at
 * which the two could contradict each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('kind', 20)
                ->default(JournalEntryKind::Standard->value)
                ->after('status');

            // Read to find a year's opening entry, which happens on every visit
            // to the opening balances screen.
            $table->index(['company_id', 'kind'], 'journal_entries_company_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropIndex('journal_entries_company_kind_idx');
            $table->dropColumn('kind');
        });
    }
};
