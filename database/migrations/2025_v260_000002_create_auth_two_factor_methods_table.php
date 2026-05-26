<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_two_factor_methods')) {
            return;
        }

        Schema::create('auth_two_factor_methods', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('type', 32);           // totp|email|sms
            $table->text('secret_encrypted')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type'], 'auth_2fa_methods_user_type_unique');
            $table->index(['user_id', 'verified_at'], 'auth_2fa_methods_user_verified_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_two_factor_methods');
    }
};
