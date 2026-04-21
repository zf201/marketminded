<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('voice_analysis')->nullable();
            $table->text('content_types')->nullable();
            $table->text('should_avoid')->nullable();
            $table->text('should_use')->nullable();
            $table->text('style_inspiration')->nullable();
            $table->integer('preferred_length')->default(1500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_profiles');
    }
};
