<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_two_factor_backup_codes')) {
            return;
        }

        Schema::create('auth_two_factor_backup_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('code_hash', 255);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'used_at'], 'auth_2fa_backup_user_used_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_two_factor_backup_codes');
    }
};
