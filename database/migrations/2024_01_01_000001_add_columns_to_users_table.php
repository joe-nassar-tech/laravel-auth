<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('users', 'password_change_required')) {
                $table->boolean('password_change_required')->default(false)->after('remember_token');
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('password_change_required');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $columns = array_filter(
                ['google_id', 'password_change_required', 'is_active', 'last_login_at'],
                fn (string $col) => Schema::hasColumn('users', $col),
            );

            if ($columns !== []) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
