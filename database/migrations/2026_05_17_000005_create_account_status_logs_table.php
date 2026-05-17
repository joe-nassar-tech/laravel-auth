<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('auth_system.account.audit.table', 'account_status_logs');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            // Target user — deliberately NO foreign key so the audit row
            // outlives a hard-delete of the users row.
            $table->unsignedBigInteger('user_id')->index();
            // 'admin' (admin_id = actor), 'user' (target self-action),
            // or 'system' (automated transition, actor_id is null).
            $table->string('actor_type', 16)->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            // 'status_change' or 'note'.
            $table->string('action', 32)->index();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('reason')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Context tag like 'admin_endpoint', 'self_deactivate',
            // 'auto_unban_lazy', 'auto_unban_sweep', 'login_auto_restore',
            // 'login_auto_reactivate', 'self_delete', 'purge_worker',
            // 'admin_note'. Free-form so host apps can extend.
            $table->string('source', 64)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        $table = (string) config('auth_system.account.audit.table', 'account_status_logs');

        Schema::dropIfExists($table);
    }
};
