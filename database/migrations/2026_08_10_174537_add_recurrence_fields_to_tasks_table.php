<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // null recurrence_type = a normal, non-recurring task (the
            // pre-existing default). Completing a recurring task spawns a
            // new Task row for the next occurrence rather than mutating
            // this one -- see TaskRecurrenceService -- so completed
            // occurrences stay in history instead of disappearing.
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly', 'custom_days'])
                ->nullable()->after('due_date');
            $table->unsignedInteger('recurrence_interval')->default(1)->after('recurrence_type');

            // 'due_date' = fixed schedule, next occurrence is always N units
            // after this one's due date regardless of when it was actually
            // completed ("every Monday"). 'completion' = floating schedule,
            // next occurrence is N units after the day it was actually
            // completed ("every 3 days after I finish it").
            $table->enum('recurrence_anchor', ['due_date', 'completion'])
                ->default('due_date')->after('recurrence_interval');

            // Points at the very first task in a recurring series (null on
            // that first task itself) -- lets every occurrence be found via
            // one query regardless of how many times it's recurred.
            $table->foreignId('recurrence_parent_id')->nullable()->after('recurrence_anchor')
                ->constrained('tasks')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurrence_parent_id');
            $table->dropColumn(['recurrence_type', 'recurrence_interval', 'recurrence_anchor']);
        });
    }
};
