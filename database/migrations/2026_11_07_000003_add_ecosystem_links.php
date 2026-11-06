<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a file was also kept in Dot.Files, and the Dot.Projects page a task belongs to. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_attachments', function (Blueprint $table) {
            $table->string('file_uuid', 64)->nullable();
            $table->string('open_url', 500)->nullable();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('project_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('project_url'));
        Schema::table('task_attachments', fn (Blueprint $table) => $table->dropColumn(['file_uuid', 'open_url']));
    }
};
