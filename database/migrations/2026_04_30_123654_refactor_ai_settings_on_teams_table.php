<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->renameColumn('openrouter_api_key', 'ai_api_key');
            $table->string('ai_provider')->default('openrouter')->after('ai_api_key');
            $table->string('ai_api_url')->nullable()->after('ai_provider');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['ai_provider', 'ai_api_url']);
            $table->renameColumn('ai_api_key', 'openrouter_api_key');
        });
    }
};
