<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_phone_otp_codes')) {
            return;
        }

        Schema::create('auth_phone_otp_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('phone', 32);
            $table->string('purpose', 32); // phone_verify|two_factor_sms
            $table->string('code_hash', 255);
            $table->string('channel', 16); // sms|voice|whatsapp
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['phone', 'purpose', 'consumed_at'], 'auth_phone_otp_lookup_index');
            $table->index('expires_at', 'auth_phone_otp_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_phone_otp_codes');
    }
};
