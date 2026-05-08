<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_sessions_extended', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('session_id', 255)->nullable();
            $table->unsignedBigInteger('sanctum_token_id')->nullable();
            $table->enum('platform', ['web', 'mobile', 'api'])->default('web');
            $table->string('browser', 100)->nullable();
            $table->string('os', 100)->nullable();
            $table->string('device_model', 255)->nullable();
            $table->string('device_marketing_name', 255)->nullable();
            $table->string('device_code', 50)->nullable();
            $table->string('device_platform', 50)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->timestamp('last_active_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('session_id');
            $table->index('sanctum_token_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions_extended');
    }
};
