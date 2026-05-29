<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.7.1 — record who *created* an API token (audit binding). For user-
     * issued tokens this equals the owner; for admin-issued (unowned) tokens
     * it's the admin who minted it, so unowned tokens are still attributable.
     */
    public function up(): void
    {
        if (! Schema::hasTable('auth_api_tokens')) {
            return;
        }

        Schema::table('auth_api_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('auth_api_tokens', 'created_by_id')) {
                $table->unsignedBigInteger('created_by_id')->nullable()->after('owner_id');
            }
            if (! Schema::hasColumn('auth_api_tokens', 'created_by_type')) {
                $table->string('created_by_type')->nullable()->after('created_by_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('auth_api_tokens')) {
            return;
        }

        Schema::table('auth_api_tokens', function (Blueprint $table): void {
            if (Schema::hasColumn('auth_api_tokens', 'created_by_id')) {
                $table->dropColumn('created_by_id');
            }
            if (Schema::hasColumn('auth_api_tokens', 'created_by_type')) {
                $table->dropColumn('created_by_type');
            }
        });
    }
};
