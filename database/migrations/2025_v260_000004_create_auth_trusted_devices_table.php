<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_trusted_devices')) {
            return;
        }

        Schema::create('auth_trusted_devices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('fingerprint_hash', 128);
            $table->string('device_name', 255)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('os', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('level', 16)->default('low'); // low|medium|high
            $table->boolean('admin_granted')->default(false);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('trusted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint_hash'], 'auth_trusted_devices_user_fp_unique');
            $table->index(['user_id', 'revoked_at'], 'auth_trusted_devices_user_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_trusted_devices');
    }
};
