<?php

declare(strict_types=1);

use App\Enums\DimensionScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-defined accounting dimensions.
 *
 * Modelled on Qoyod's own design: a company creates its own dimensions — cost
 * centre, project, department, campaign — each holding a set of values, and tags
 * ledger movements with them. Reporting can then be sliced by any dimension or
 * combination, which is how a per-project income statement is produced without
 * multiplying account codes.
 *
 * Each dimension is either *general* or *specific*. A general dimension applies
 * across every document in the system and feeds the consolidated reports;
 * Qoyod permits at most two, and that limit is enforced here — see
 * {@see \App\Observers\DimensionObserver}. A specific dimension is scoped to the
 * documents that opt into it.
 *
 * This replaces the fixed `cost_centers` table shipped earlier. Cost centre is a
 * dimension, not a distinct concept, and keeping both would have meant two
 * mechanisms answering the same question. `branches` is retained, because a
 * location is a real entity that inventory, point-of-sale and ZATCA branch
 * reporting all attach to — not merely an analytical tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dimensions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 30);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('scope', 20)->default(DimensionScope::Specific->value);

            // A required dimension must be supplied on every line it applies to.
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'scope']);
        });

        Schema::create('dimension_values', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('dimension_id')->constrained()->cascadeOnDelete();
            // Values nest, so a sub-project rolls up into its programme.
            $table->foreignUlid('parent_id')->nullable()
                ->constrained('dimension_values')->nullOnDelete();

            $table->string('code', 30);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['dimension_id', 'code']);
            $table->index(['company_id', 'dimension_id']);
            $table->index('parent_id');
        });

        Schema::create('journal_entry_line_dimensions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('journal_entry_line_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('dimension_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('dimension_value_id')
                ->constrained('dimension_values')->restrictOnDelete();

            $table->timestamps();

            // Index names are given explicitly. Laravel derives them from the
            // table and column names, and this table's name plus two long
            // column names exceeds MySQL's 64-character identifier limit.
            //
            // One value per dimension per line. Two values for the same
            // dimension would make any total double-count that line.
            $table->unique(['journal_entry_line_id', 'dimension_id'], 'jeld_line_dimension_unique');
            // The reporting query: every movement carrying a given value.
            $table->index(['company_id', 'dimension_value_id'], 'jeld_company_value_index');
            $table->index(['company_id', 'dimension_id'], 'jeld_company_dimension_index');
        });

        // Cost centre is now a dimension. Both tables are empty, so there is
        // nothing to migrate across.
        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cost_center_id');
        });

        Schema::dropIfExists('cost_centers');
    }

    public function down(): void
    {
        Schema::create('cost_centers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()
                ->constrained('cost_centers')->nullOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
        });

        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            $table->foreignUlid('cost_center_id')->nullable()
                ->constrained()->nullOnDelete();
        });

        Schema::dropIfExists('journal_entry_line_dimensions');
        Schema::dropIfExists('dimension_values');
        Schema::dropIfExists('dimensions');
    }
};
