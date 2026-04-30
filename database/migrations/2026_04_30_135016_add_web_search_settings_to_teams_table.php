<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('web_search_provider')->default('openrouter_builtin')->after('ai_api_url');
            $table->text('brave_api_key')->nullable()->after('web_search_provider');
            $table->string('countries')->nullable()->after('brave_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['web_search_provider', 'brave_api_key', 'countries']);
        });
    }
};
