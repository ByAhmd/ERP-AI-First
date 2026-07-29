<?php

declare(strict_types=1);

use App\Enums\PeriodStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal years and the periods within them.
 *
 * A posting is accepted only if its date falls inside a period that is open.
 * This is what stops a closed month being altered after its figures have been
 * reported or filed.
 *
 * Uniqueness is on (fiscal_year_id, name), not (company_id, name) as in the
 * predecessor, where two different years could not both contain a period called
 * "January".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name', 60);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default(PeriodStatus::Open->value);

            // Set when the year is closed and its result transferred to
            // retained earnings; retained so the transfer is traceable.
            $table->timestamp('closed_at')->nullable();
            $table->foreignUlid('closed_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status']);
            // Range lookups resolve a posting date to its year.
            $table->index(['company_id', 'start_date', 'end_date']);
        });

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('fiscal_year_id')->constrained()->cascadeOnDelete();

            $table->string('name', 60);
            // Ordinal within the year; period 13 is conventionally the
            // year-end adjustment period.
            $table->unsignedTinyInteger('sequence');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default(PeriodStatus::Open->value);

            $table->timestamp('closed_at')->nullable();
            $table->foreignUlid('closed_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['fiscal_year_id', 'name']);
            $table->unique(['fiscal_year_id', 'sequence']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
