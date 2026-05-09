<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // Nullable: the paired access token may be deleted (e.g. by logout)
            // before the refresh token is consumed or cleaned up.
            $table->unsignedBigInteger('access_token_id')->nullable()->index();
            // SHA-256 hex of the raw random token — only the hash is stored.
            $table->char('token_hash', 64)->unique();
            // Token rotation lineage. All descendants of an initial token share
            // the same family_id; on detected reuse the entire family is revoked.
            $table->uuid('family_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Set when the token is used for a refresh (one-time use).
            $table->timestamp('consumed_at')->nullable();
            // Set when the family is revoked (replay detected, logout-all, etc).
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'consumed_at']);
            $table->index('family_id');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_refresh_tokens');
    }
};
