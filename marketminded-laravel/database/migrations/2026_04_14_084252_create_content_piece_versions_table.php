<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_piece_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_piece_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('body');
            $table->text('change_description')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['content_piece_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_piece_versions');
    }
};
