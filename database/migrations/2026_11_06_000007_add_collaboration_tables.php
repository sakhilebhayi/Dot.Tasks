<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Replies and internal notes, files, followers, what each person wants to hear about, and each team's own clock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->boolean('internal')->default(false);
        });

        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->string('name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->index('task_id');
        });

        Schema::create('task_watchers', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_settings')->nullable();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('timezone'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notification_settings'));
        Schema::dropIfExists('task_watchers');
        Schema::dropIfExists('task_attachments');
        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
            $table->dropColumn(['parent_id', 'internal']);
        });
    }
};
