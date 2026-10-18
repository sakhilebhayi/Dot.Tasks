<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only trail of tasks brought back from the Removed page: who restored
 * what and when. The label is a snapshot, because the task itself may be purged
 * later. Rows are never updated or deleted by the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_restorations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('record_type', 16);
            $table->unsignedBigInteger('record_id');
            $table->string('record_label');
            $table->unsignedInteger('restored_with')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_restorations');
    }
};
