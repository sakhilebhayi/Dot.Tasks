<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a person means to do on a day, in the order they mean to do it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'task_id', 'day']);
            $table->index(['user_id', 'day', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_plans');
    }
};
