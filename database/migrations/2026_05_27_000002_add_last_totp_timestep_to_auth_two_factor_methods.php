<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.7 — TOTP replay protection. Stores the last successfully-verified
     * RFC 6238 time-step for a TOTP method so a code cannot be replayed within
     * its validity window; verification rejects any step <= this value.
     */
    public function up(): void
    {
        if (! Schema::hasTable('auth_two_factor_methods')) {
            return;
        }

        Schema::table('auth_two_factor_methods', function (Blueprint $table): void {
            if (! Schema::hasColumn('auth_two_factor_methods', 'last_totp_timestep')) {
                $table->unsignedBigInteger('last_totp_timestep')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('auth_two_factor_methods', 'last_totp_timestep')) {
            Schema::table('auth_two_factor_methods', function (Blueprint $table): void {
                $table->dropColumn('last_totp_timestep');
            });
        }
    }
};
