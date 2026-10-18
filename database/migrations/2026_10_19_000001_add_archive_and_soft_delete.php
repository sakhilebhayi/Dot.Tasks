<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive (reversible, hidden by default) and soft delete for tasks, archive
 * for boards. Plain nullable timestamps so it runs on SQLite and Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index('tasks_archived_at_index');
            $table->softDeletes();
        });

        Schema::table('task_lists', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_archived_at_index');
            $table->dropColumn(['archived_at', 'deleted_at']);
        });

        Schema::table('task_lists', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
