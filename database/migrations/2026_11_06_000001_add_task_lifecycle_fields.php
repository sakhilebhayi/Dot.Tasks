<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the core of a task needs: dates, effort, how it is finished, who else is on it, its steps and its history.
 * Plain columns on tasks: on SQLite a new foreign key rebuilds this table, and a rebuild sets off the delete rules on
 * its parent columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->date('start_date')->nullable();
            $table->unsignedInteger('actual_minutes')->nullable();
            $table->text('completion_criteria')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->index('completed_at', 'tasks_completed_at_index');
        });

        // The moment was not kept before now; the last change is the nearest honest stand-in.
        DB::table('tasks')->where('status', 'done')->whereNull('completed_at')->update(['completed_at' => DB::raw('updated_at')]);

        Schema::table('task_lists', function (Blueprint $table) {
            $table->boolean('is_inbox')->default(false);
        });

        Schema::create('task_collaborators', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['task_id', 'sort_order']);
        });

        Schema::create('task_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 40);
            $table->string('summary', 300);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['task_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('task_collaborators');
        Schema::table('task_lists', fn (Blueprint $table) => $table->dropColumn('is_inbox'));
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_completed_at_index');
            $table->dropColumn(['start_date', 'actual_minutes', 'completion_criteria', 'completion_note', 'completed_at', 'completed_by']);
        });
    }
};
