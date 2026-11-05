<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each board's own columns. `tasks.status` stays as the category a column belongs to (to do, in progress, review or
 * done), because finishing, reminders and every report mean the category and not the name. Plain column on tasks: on
 * SQLite a new foreign key rebuilds this table, and a rebuild sets off the delete rules on its parent columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_list_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('category', 20);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['task_list_id', 'name']);
            $table->index(['task_list_id', 'sort_order']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('status_id')->nullable();
            $table->index(['status_id', 'sort_order'], 'tasks_status_id_sort_index');
        });

        $names = ['todo' => 'To Do', 'in_progress' => 'In Progress', 'review' => 'Review', 'done' => 'Done'];

        DB::table('task_lists')->orderBy('id')->pluck('id')->each(function (int $list) use ($names) {
            $order = 0;
            foreach ($names as $category => $name) {
                $id = DB::table('task_statuses')->insertGetId([
                    'task_list_id' => $list, 'name' => $name, 'category' => $category, 'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('tasks')->where('task_list_id', $list)->where('status', $category)->update(['status_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_status_id_sort_index');
            $table->dropColumn('status_id');
        });

        Schema::dropIfExists('task_statuses');
    }
};
