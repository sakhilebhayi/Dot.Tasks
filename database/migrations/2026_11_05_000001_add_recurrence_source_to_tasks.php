<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The task an occurrence was made from. Unique, so finishing the same task twice can never make a second next
 * occurrence, even when two people finish it at the same moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // No foreign key: on SQLite adding or dropping one rebuilds the table, and rebuilding this table sets off
            // the delete rules on its parent columns. A dangling id here is harmless once a source has been purged.
            $table->unsignedBigInteger('recurrence_source_id')->nullable();
            $table->unique('recurrence_source_id', 'tasks_recurrence_source_unique');
        });

        // Each occurrence so far came from the one before it in its series, oldest first.
        $series = DB::table('tasks')->whereNotNull('recurrence_parent_id')->orderBy('recurrence_parent_id')->orderBy('id')->get(['id', 'recurrence_parent_id']);
        $previous = [];
        foreach ($series as $occurrence) {
            $root = $occurrence->recurrence_parent_id;
            DB::table('tasks')->where('id', $occurrence->id)->update(['recurrence_source_id' => $previous[$root] ?? $root]);
            $previous[$root] = $occurrence->id;
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique('tasks_recurrence_source_unique');
            $table->dropColumn('recurrence_source_id');
        });
    }
};
