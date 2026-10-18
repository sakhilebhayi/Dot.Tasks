<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries the app actually runs: dashboard counts and
 * SmartViews filter by status / due_date, "my tasks" by assignee_id, and
 * each board loads one list's tasks by status ordered by sort_order.
 * Plain Blueprint indexes, so they work on SQLite and Postgres alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index('status', 'tasks_status_index');
            $table->index('due_date', 'tasks_due_date_index');
            $table->index('assignee_id', 'tasks_assignee_id_index');
            $table->index(['task_list_id', 'status', 'sort_order'], 'tasks_list_status_sort_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_status_index');
            $table->dropIndex('tasks_due_date_index');
            $table->dropIndex('tasks_assignee_id_index');
            $table->dropIndex('tasks_list_status_sort_index');
        });
    }
};
