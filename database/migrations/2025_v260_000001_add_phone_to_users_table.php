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
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 32)->nullable()->after('email');
                $table->index('phone', 'users_phone_index');
            }
            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'two_factor_required')) {
                $table->boolean('two_factor_required')->default(false)->after('phone_verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['phone', 'phone_verified_at', 'two_factor_required'],
                fn (string $col) => Schema::hasColumn('users', $col),
            ));

            if (in_array('phone', $columns, true)) {
                try { $table->dropIndex('users_phone_index'); } catch (\Throwable) {}
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
