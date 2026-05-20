<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The user who owns the referral_code that was used.
            $table->unsignedBigInteger('referrer_id');

            // The newly-registered user who used the code.
            $table->unsignedBigInteger('referred_id');

            // The exact code string the user submitted. Stored as a snapshot
            // so changing/regenerating a code later doesn't break audit.
            $table->string('referral_code', 64);

            // pending  → reward handler hasn't run yet (e.g. queued)
            // valid    → passed all abuse checks, reward eligible
            // suspicious → flagged for review, NO reward fired
            // blocked  → blocked by config rule, NO reward fired
            // expired  → redeem attempt outside the time window
            $table->enum('status', ['pending', 'valid', 'suspicious', 'blocked', 'expired'])
                ->default('pending');

            // Fingerprint snapshots — referrer's is read from their session
            // history at the moment the referral is created. Both are kept
            // so admin can audit the comparison after the fact.
            $table->string('referrer_fingerprint', 191)->nullable();
            $table->string('referred_fingerprint', 191)->nullable();
            $table->string('referrer_ip', 45)->nullable();
            $table->string('referred_ip', 45)->nullable();

            // Cached comparison results — set by ReferralService at create
            // time so admin queries don't have to recompute on every read.
            $table->boolean('ip_match')->default(false);
            $table->boolean('device_match')->default(false);

            // When the reward handler fired successfully (null until then).
            $table->timestamp('redeemed_at')->nullable();

            // Free-form admin note set by the override endpoint, e.g.
            // "Confirmed legitimate referral — two roommates."
            $table->string('admin_note', 500)->nullable();

            $table->timestamps();

            $table->index('referrer_id');
            $table->index('referred_id');
            $table->index('status');
            // Each new user can only ever be referred once.
            $table->unique('referred_id', 'referrals_referred_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
