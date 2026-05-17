<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $column = (string) config('auth_system.account.status.column', 'account_status');
        $default = (string) config('auth_system.account.status.default', 'active');

        Schema::table('users', function (Blueprint $table) use ($column, $default): void {
            if (! Schema::hasColumn('users', $column)) {
                $table->string($column, 32)->default($default)->index();
            }
            if (! Schema::hasColumn('users', 'status_changed_at')) {
                $table->timestamp('status_changed_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'status_reason')) {
                $table->text('status_reason')->nullable();
            }
            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        $column = (string) config('auth_system.account.status.column', 'account_status');

        Schema::table('users', function (Blueprint $table) use ($column): void {
            $columns = array_filter(
                [$column, 'status_changed_at', 'status_reason', 'deleted_at'],
                fn (string $c) => Schema::hasColumn('users', $c),
            );
            if ($columns !== []) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
