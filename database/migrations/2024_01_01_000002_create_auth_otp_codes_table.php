<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_otp_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->enum('type', ['email_verify', 'password_reset', 'magic_link_verify', 'magic_link_reset']);
            $table->string('token', 64); // SHA-256 hex of the raw OTP or magic-link UUID
            $table->string('temp_token')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            // Per-OTP brute-force counter. Incremented on each failed validate.
            // When this hits the configured threshold, the row is invalidated.
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('token');
            $table->index(['email', 'type', 'used_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_otp_codes');
    }
};
