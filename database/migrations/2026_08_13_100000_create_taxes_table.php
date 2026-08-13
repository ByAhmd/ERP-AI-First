<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT rates a document line can be charged at.
 *
 * A rate is configuration rather than a constant. Saudi VAT has moved from 5%
 * to 15% inside the lifetime of a working set of books, and an invoice issued
 * before the change must keep reporting the rate it was actually raised at —
 * which is why the line stores the resolved figures rather than recomputing
 * them from whatever this table says today.
 *
 * Each rate names the account its tax posts to, so the posting target is
 * configured once here instead of being decided by every document that charges
 * it. Qoyod does the same, and its `الحساب` column is this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('name_en')->nullable();

            // ZATCA's tax category code — S, Z or E. Not derivable from the
            // rate: zero-rated and exempt are both 0% and are filed
            // differently.
            $table->string('category', 1);
            $table->decimal('rate', 7, 4)->default(0);

            // Where the tax lands. Restricted rather than cascaded: deleting an
            // account out from under a tax would leave documents unable to
            // post.
            $table->foreignUlid('account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            // Applied when a document line names no tax of its own.
            $table->boolean('is_default')->default(false);
            // Seeded rates may be renamed but not deleted; documents resolve
            // them by category.
            $table->boolean('is_system')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name'], 'taxes_company_name_unique');
            $table->index(['company_id', 'is_active'], 'taxes_company_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
