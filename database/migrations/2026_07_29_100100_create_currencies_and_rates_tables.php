<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Currencies a company transacts in, and the rates used to translate them.
 *
 * Currencies are per company rather than global: a company enables the handful
 * it actually uses, and its base currency is one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->char('code', 3);
            $table->string('name');
            $table->string('symbol', 8)->nullable();
            // Most currencies use 2; KWD, BHD and OMR use 3, JPY uses 0.
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('currency_id')->constrained()->cascadeOnDelete();

            $table->date('rate_date');
            // Units of base currency per one unit of the quoted currency.
            $table->decimal('rate', 19, 6);

            $table->timestamps();

            // One rate per currency per day. A second rate for the same day
            // would make translation depend on insertion order.
            $table->unique(['company_id', 'currency_id', 'rate_date']);
            $table->index(['company_id', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
