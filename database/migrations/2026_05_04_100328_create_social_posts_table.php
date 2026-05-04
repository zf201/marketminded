<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_piece_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 20);
            $table->text('hook');
            $table->text('body');
            $table->json('hashtags')->default('[]');
            $table->text('image_prompt')->nullable();
            $table->text('video_treatment')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'content_piece_id', 'status']);
            $table->index(['content_piece_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
