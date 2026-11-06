<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rules a team writes, each time one ran, and the people asked to approve a task. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_list_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('trigger', 30);
            $table->string('match', 3)->default('all');
            $table->json('conditions');
            $table->json('actions');
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->boolean('needs_approval')->default(false);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->index(['team_id', 'trigger', 'enabled']);
        });

        Schema::create('task_rule_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 120);
            $table->string('status', 20);
            $table->timestamp('run_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['task_rule_id', 'event_key']);
            $table->index(['status', 'run_at']);
        });

        Schema::create('task_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('pending');
            $table->string('note', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'status']);
            $table->index(['approver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_approvals');
        Schema::dropIfExists('task_rule_runs');
        Schema::dropIfExists('task_rules');
    }
};
