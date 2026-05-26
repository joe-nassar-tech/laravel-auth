<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_trusted_devices', function (Blueprint $table): void {
            if (! Schema::hasColumn('auth_trusted_devices', 'secret_hash')) {
                // SHA-256 hex of a 32-byte random token. The plaintext is
                // returned to the client ONCE at trust time and must be sent
                // back as X-Trusted-Device-Token on subsequent logins for
                // the device to bypass 2FA. Without this header (or with a
                // non-matching value) a device that matches by fingerprint
                // alone is treated as untrusted — fingerprint is a client-
                // controlled signal and must not be the sole bypass key.
                $table->string('secret_hash', 64)->nullable()->after('fingerprint_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auth_trusted_devices', function (Blueprint $table): void {
            if (Schema::hasColumn('auth_trusted_devices', 'secret_hash')) {
                $table->dropColumn('secret_hash');
            }
        });
    }
};
