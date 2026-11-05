<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a business wants to record on its tasks, and the shapes of work it repeats. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 10);
            $table->json('options')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['team_id', 'name']);
        });

        Schema::create('task_field_values', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_field_id')->constrained()->cascadeOnDelete();
            $table->string('value', 500);
            $table->primary(['task_id', 'task_field_id']);
            $table->index(['task_field_id', 'value']);
        });

        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('medium');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('due_in_days')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->json('subtasks')->nullable();
            $table->json('checklist')->nullable();
            $table->json('label_ids')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['team_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_templates');
        Schema::dropIfExists('task_field_values');
        Schema::dropIfExists('task_fields');
    }
};
