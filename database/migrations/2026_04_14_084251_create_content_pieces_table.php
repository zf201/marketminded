<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pieces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('body');
            $table->string('status', 20)->default('draft');
            $table->string('platform', 20)->default('blog');
            $table->string('format', 30)->default('pillar');
            $table->unsignedInteger('current_version')->default(0);
            $table->timestamps();
            $table->index(['team_id', 'status']);
            $table->index('topic_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pieces');
    }
};
