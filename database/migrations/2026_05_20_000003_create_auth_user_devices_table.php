<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent device history.
 *
 * Differs from `auth_sessions_extended` in one critical way: rows here
 * SURVIVE LOGOUT. They are the user's lifetime record of "every device
 * that has ever logged into this account" — used both as a security
 * audit surface (lets the user spot stolen credentials) and as the
 * backing store for the referral anti-abuse system.
 *
 * Without this table the referral check would only see the referrer's
 * currently-active sessions, so a careful attacker could log out before
 * creating their second account and dodge detection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_user_devices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');

            // Strong device-level identifier:
            //   web:    SHA-256 hash from X-Browser-Fingerprint header
            //   mobile: device_id from X-Device-Info (Keychain / ANDROID_ID)
            // Null when the frontend hasn't implemented the JS snippet yet.
            $table->string('fingerprint_hash', 191)->nullable();

            // Stable per-device signature used for de-duplication when the
            // strong fingerprint is missing. Built from (browser|os|platform)
            // by UserDeviceService so the table doesn't accumulate duplicates
            // for every login from the same physical computer.
            $table->string('device_signature', 191);

            // Snapshot of the most recent observation. Update on each touch.
            $table->string('ip_address', 45)->nullable();
            $table->enum('platform', ['web', 'mobile', 'api'])->default('web');
            $table->string('browser', 100)->nullable();
            $table->string('os', 100)->nullable();
            $table->string('device_model', 255)->nullable();
            $table->string('device_marketing_name', 255)->nullable();
            $table->string('device_code', 50)->nullable();
            $table->string('device_platform', 50)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();

            // first_seen never moves after insert; last_seen updates on every
            // touch. Both are surfaced in GET /auth/devices.
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();

            $table->index('user_id');
            $table->index('fingerprint_hash');
            // The referral abuse query is "does any device under this user
            // match this fingerprint/ip?" — composite indexes keep it O(log n).
            $table->index(['user_id', 'fingerprint_hash']);
            $table->index(['user_id', 'ip_address']);
            // De-duplication unique. One row per (user, signature) combo.
            $table->unique(['user_id', 'device_signature'], 'auth_user_devices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_user_devices');
    }
};
