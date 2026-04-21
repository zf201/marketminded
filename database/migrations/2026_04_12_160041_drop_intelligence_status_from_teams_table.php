<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['intelligence_status', 'intelligence_error']);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('intelligence_status', 50)->nullable()->after('content_language');
            $table->text('intelligence_error')->nullable()->after('intelligence_status');
        });
    }
};
