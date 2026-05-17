<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('google_id')->nullable();
            $table->string('name');
            // Nullable so the v2.4 purge worker can null the unique column
            // post-grace to free the address for reuse. Real host apps should
            // do the same on their users.email column.
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->boolean('password_change_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            // Optional columns used by v2.2 referral / transformer tests
            $table->string('referral_code')->nullable()->unique();
            $table->string('username')->nullable();
            $table->string('username_normalized')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
