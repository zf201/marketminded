<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_positionings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('value_proposition')->nullable();
            $table->text('target_market')->nullable();
            $table->text('differentiators')->nullable();
            $table->text('core_problems')->nullable();
            $table->text('products_services')->nullable();
            $table->text('primary_cta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_positionings');
    }
};
