<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting dimensions attached to ledger movements.
 *
 * Branches and cost centres let the same chart of accounts be sliced by where
 * and by whom an amount was incurred, without multiplying the account codes.
 *
 * The predecessor created both tables and then referenced neither, so every
 * figure was company-wide only. Here they are carried on the journal line,
 * which is the level at which the question is actually asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 20);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('cost_centers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            // Cost centres nest, so a department can roll up into a division.
            $table->foreignUlid('parent_id')->nullable()
                ->constrained('cost_centers')->nullOnDelete();

            $table->string('code', 20);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('branches');
    }
};
