<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_sessions_extended', function (Blueprint $table): void {
            // Strong device-level fingerprint hash used by the referral
            // anti-abuse system.
            // - Web/SPA: client-computed hash sent via X-Browser-Fingerprint
            //   (canvas + WebGL + screen + timezone, etc.)
            // - Mobile:  device_id from X-Device-Info (UUID kept in iOS Keychain
            //   or ANDROID_ID on Android)
            // Nullable because the frontend may not yet implement the JS snippet
            // — the package silently degrades to IP-only matching in that case.
            $table->string('fingerprint_hash', 191)->nullable()->after('device_platform');

            $table->index('fingerprint_hash');
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions_extended', function (Blueprint $table): void {
            $table->dropIndex(['fingerprint_hash']);
            $table->dropColumn('fingerprint_hash');
        });
    }
};
