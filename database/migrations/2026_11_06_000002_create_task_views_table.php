<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A person's own named way of looking at a board. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_list_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('settings');
            $table->timestamps();
            $table->unique(['user_id', 'task_list_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_views');
    }
};
