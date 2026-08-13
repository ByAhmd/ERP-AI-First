<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field-level audit trail.
 *
 * Diverges from the package's published stub in three ways:
 *
 *  - Morph keys are ULIDs, matching the platform's key strategy.
 *  - `company_id` is denormalised onto each row so that a company's audit history
 *    can be read, retained and purged without joining every audited table. This
 *    is one of the documented exceptions to the no-denormalisation rule.
 *  - Value columns use JSON rather than TEXT, so changed fields are queryable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $tableName = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->create($tableName, function (Blueprint $table): void {
            $morphPrefix = config('audit.user.morph_prefix', 'user');

            $table->bigIncrements('id');

            $table->string($morphPrefix.'_type')->nullable();
            $table->ulid($morphPrefix.'_id')->nullable();

            $table->ulid('company_id')->nullable();

            $table->string('event');
            $table->ulidMorphs('auditable');

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();

            $table->timestamps();

            $table->index([$morphPrefix.'_id', $morphPrefix.'_type']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $tableName = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->dropIfExists($tableName);
    }
};
