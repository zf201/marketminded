<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendar_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained('content_calendars')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_for');
            $table->string('title');
            $table->text('image_headline')->nullable();
            $table->text('image_prompt')->nullable();
            $table->text('linkedin_copy')->nullable();
            $table->text('instagram_copy')->nullable();
            $table->text('facebook_copy')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('source_topic_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->foreignId('source_social_post_id')->nullable()->constrained('social_posts')->nullOnDelete();
            $table->foreignId('source_content_piece_id')->nullable()->constrained('content_pieces')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->unique(['calendar_id', 'scheduled_for']);
            $table->index(['team_id', 'scheduled_for']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_entries');
    }
};
