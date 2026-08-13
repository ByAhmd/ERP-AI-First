<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Tenancy\CompanyIsolationTest;

/**
 * A table that exists only so the tenancy trait can be tested on its own.
 *
 * {@see CompanyIsolationTest} proves what
 * `BelongsToCompany` guarantees. Doing that against a real model would prove
 * something narrower — that model's observers, casts and validation all
 * participate — and would tie the guarantee to whichever model was chosen.
 * A table with nothing on it but a company and a label leaves only the trait.
 *
 * It lives here rather than in `database/migrations` because it must never
 * reach a real database. `Tests\TestCase` registers this directory with the
 * migrator, so `migrate:fresh` picks it up for the test run and nowhere else.
 *
 * The 9999 prefix is not decoration: migrations are ordered by filename across
 * every registered path, and the foreign key below requires `companies` to
 * exist first.
 *
 * Previously the test created this table in `setUp()`. That ran inside the
 * transaction RefreshDatabase opens per test, and DDL commits implicitly in
 * MySQL — so the wrapping transaction was discarded and each test committed
 * its fixtures for the next one to trip over. It survived only because the
 * suite never asked the connection to do anything else on the way in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_probes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_probes');
    }
};
