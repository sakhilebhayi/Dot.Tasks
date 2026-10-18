<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who made the task, so they hear about comments on it. Null for tasks
        // that existed before this column, and when the creator is deleted.
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // "task_id is blocked by blocked_by_id". Both ends are tasks of the
        // same board (enforced when written); the pair is unique.
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('blocked_by_id')->constrained('tasks')->cascadeOnDelete();
            $table->primary(['task_id', 'blocked_by_id']);
            $table->index('blocked_by_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creator_id');
        });
    }
};
