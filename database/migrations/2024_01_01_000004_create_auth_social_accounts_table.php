<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 50);
            $table->string('provider_id', 255);
            $table->string('provider_email', 255)->nullable();
            $table->text('avatar')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'provider_id'], 'unique_provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_social_accounts');
    }
};
