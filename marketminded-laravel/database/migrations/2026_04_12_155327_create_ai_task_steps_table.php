<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_task_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_task_id')->constrained()->cascadeOnDelete();
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

    public function down(): void
    {
        Schema::dropIfExists('ai_task_steps');
    }
};
