<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gapless document numbering.
 *
 * One row per company, document type and reset scope. Numbers are allocated by
 * locking the row inside the same transaction that creates the document, so a
 * failed insert rolls the counter back with it.
 *
 * The predecessor derived journal numbers from `COUNT(*) + 1`, which races under
 * concurrency, and consumed invoice numbers outside the document's transaction,
 * so a failed save burned a number permanently. ZATCA requires the sequence to
 * be unbroken, which makes both defects a compliance problem rather than a
 * cosmetic one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            // The document type, e.g. journal_entry, sales_invoice, credit_note.
            $table->string('key', 60);

            // Sequences that restart each year carry the year here; those that
            // run continuously carry an empty string. A nullable column would
            // not work: MySQL treats NULLs as distinct in a unique index, which
            // would permit duplicate continuous sequences.
            $table->string('scope', 20)->default('');

            $table->string('prefix', 20)->default('');
            $table->string('suffix', 20)->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(4);

            $table->timestamps();

            $table->unique(['company_id', 'key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
