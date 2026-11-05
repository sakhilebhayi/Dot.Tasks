<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links between tasks get a type. Existing links keep the rule they always had: the blocker finishes before this does.
 * A series gets its ends and its weekdays. A year is 12 months in recurrence_type: that column is an enum, and changing
 * one on SQLite rebuilds the table, which sets off the delete rules on its parent columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_dependencies', function (Blueprint $table) {
            $table->string('type', 2)->default('ff');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->json('recurrence_days')->nullable();
            $table->date('recurrence_until')->nullable();
            $table->unsignedInteger('recurrence_limit')->nullable();
            $table->unsignedInteger('recurrence_count')->default(1);
            $table->string('recurrence_missed', 10)->default('keep');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['recurrence_days', 'recurrence_until', 'recurrence_limit', 'recurrence_count', 'recurrence_missed']);
        });

        Schema::table('task_dependencies', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
