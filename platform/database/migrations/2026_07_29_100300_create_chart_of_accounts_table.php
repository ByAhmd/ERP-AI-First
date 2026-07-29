<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts.
 *
 * A tree: parent accounts group and total, leaf accounts receive postings.
 * `is_postable` is maintained rather than computed on read, because it is
 * consulted on every single journal line and a recursive child-count per line
 * would dominate the cost of posting an entry. It is one of the values the
 * platform maintains rather than derives, and an observer keeps it truthful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()
                ->constrained('chart_of_accounts')
                // Restrict, not cascade: deleting a parent must never silently
                // remove the ledger accounts beneath it.
                ->restrictOnDelete();

            $table->string('code', 30);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('type', 20);
            $table->text('description')->nullable();

            // Leaf accounts accept postings; groups do not.
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            // System accounts are created by the platform and relied upon by
            // posting logic (VAT payable, retained earnings). They may be
            // renamed but never deleted.
            $table->boolean('is_system')->default(false);

            // Set only for accounts denominated in a foreign currency, such as
            // a USD bank account. Null means the company's base currency.
            $table->foreignUlid('currency_id')->nullable()
                ->constrained()->nullOnDelete();

            // Materialised path, e.g. "1000.1100.1110". Makes "this account and
            // everything under it" a prefix scan rather than a recursive walk,
            // which is what every report needs.
            $table->string('path', 255)->nullable();
            $table->unsignedTinyInteger('depth')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'path']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
