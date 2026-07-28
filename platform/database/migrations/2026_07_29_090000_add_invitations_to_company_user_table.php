<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitation state for company membership.
 *
 * A separate migration rather than an amendment to the create: the original has
 * been committed and may already have been run.
 *
 * Only the token *hash* is stored. The plaintext exists once, in the email. A
 * database disclosure must not hand an attacker working invitations, and an
 * invitation grants access to a company's full financial history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table): void {
            $table->string('invitation_token_hash', 64)->nullable()->after('status');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token_hash');
            $table->foreignUlid('invited_by_id')->nullable()->after('invitation_expires_at')
                ->constrained('users')->nullOnDelete();

            // Lookup is by token during acceptance, before the user is known.
            $table->unique('invitation_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invited_by_id');
            $table->dropUnique(['invitation_token_hash']);
            $table->dropColumn(['invitation_token_hash', 'invitation_expires_at']);
        });
    }
};
