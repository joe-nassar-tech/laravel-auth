<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.7 — the OTP `type` column shipped as an enum limited to the four
     * email-verify / password-reset purposes. The 2FA email method writes
     * 'two_factor_email' / 'two_factor_email_enroll', which the enum rejects
     * on strict MySQL / PostgreSQL / SQLite (CHECK constraint), so email-based
     * 2FA could never persist a code. Widen to a plain string — the
     * application layer is the source of truth for valid purposes.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('auth_otp_codes', 'type')) {
            return;
        }

        Schema::table('auth_otp_codes', function (Blueprint $table): void {
            $table->string('type', 40)->change();
        });
    }

    public function down(): void
    {
        // Intentionally NOT reverting to the enum: a downgrade would fail on
        // any row holding a 2FA type, and a string is a safe superset of the
        // original enum values.
    }
};
