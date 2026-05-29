<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.7.1 — 2FA challenge tokens are now stored as an HMAC-SHA256 hex digest
     * (64 chars) rather than the raw UUID, matching how the package stores every
     * other bearer-style secret (OTP, backup, refresh, API tokens). Widen the
     * column from uuid(36) to string(64); the unique index is preserved.
     *
     * Safe on existing installs: any in-flight challenge issued before deploy
     * simply fails to match afterward, and the user restarts the short 2FA
     * challenge from login.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('auth_two_factor_challenges', 'challenge_token')) {
            return;
        }

        Schema::table('auth_two_factor_challenges', function (Blueprint $table): void {
            $table->string('challenge_token', 64)->change();
        });
    }

    public function down(): void
    {
        // Intentionally not reverted: HMAC digests cannot be turned back into
        // UUIDs, and string(64) is a safe superset of the original uuid column.
    }
};
