<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deleted_accounts')) {
            return;
        }

        Schema::create('deleted_accounts', function (Blueprint $table): void {
            $table->id();
            // Deliberately no FK to users — the users row may be hard-deleted
            // after the grace period while this audit row lives forever.
            $table->unsignedBigInteger('original_user_id')->index();
            $table->string('email')->nullable()->index();
            $table->string('username')->nullable()->index();
            $table->text('delete_reason')->nullable();
            $table->json('snapshot');
            // Nullable so MySQL strict mode does not reject inserts that
            // construct the row in two passes. The service code always
            // populates both before the row becomes user-visible.
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('scheduled_purge_at')->nullable()->index();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_accounts');
    }
};
