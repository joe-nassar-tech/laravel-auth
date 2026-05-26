<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_two_factor_challenges')) {
            return;
        }

        Schema::create('auth_two_factor_challenges', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('challenge_token')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('method', 32)->nullable(); // selected method, null = pending switch
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('client_type', 16)->nullable(); // mobile|spa|api|null (web/session)
            $table->string('ip_address', 45)->nullable();
            $table->string('fingerprint_hash', 128)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'consumed_at'], 'auth_2fa_challenges_user_consumed_index');
            $table->index('expires_at', 'auth_2fa_challenges_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_two_factor_challenges');
    }
};
