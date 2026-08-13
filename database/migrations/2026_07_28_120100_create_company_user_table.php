<?php

declare(strict_types=1);

use App\Enums\CompanyMembershipStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company membership.
 *
 * This table is the authority on which companies a user may act for. The Filament
 * panel's company switcher and the API's company resolution both read it, so a
 * user who is not a member here cannot reach a company's data by any route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default(CompanyMembershipStatus::Active->value);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            // A user holds exactly one membership per company.
            $table->unique(['company_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
