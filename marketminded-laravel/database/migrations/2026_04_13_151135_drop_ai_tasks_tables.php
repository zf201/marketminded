<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_task_steps');
        Schema::dropIfExists('ai_tasks');
    }

    public function down(): void
    {
        Schema::create('ai_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('label');
            $table->string('status', 20)->default('pending');
            $table->string('current_step', 50)->nullable();
            $table->integer('total_steps')->default(0);
            $table->integer('completed_steps')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('total_tokens')->default(0);
            $table->decimal('total_cost', 10, 6)->default(0);
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'created_at']);
        });

        Schema::create('ai_task_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_task_id')->constrained('ai_tasks')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('label');
            $table->string('status', 20)->default('pending');
            $table->string('model')->nullable();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->decimal('cost', 10, 6)->default(0);
            $table->integer('iterations')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }
};
