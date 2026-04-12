<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->text('openrouter_api_key')->nullable()->after('is_personal');
            $table->string('fast_model')->default('deepseek/deepseek-v3.2:nitro')->after('openrouter_api_key');
            $table->string('powerful_model')->default('deepseek/deepseek-v3.2:nitro')->after('fast_model');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['openrouter_api_key', 'fast_model', 'powerful_model']);
        });
    }
};
