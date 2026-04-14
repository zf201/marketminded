<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('writer_mode', 20)->nullable()->after('type');
            $table->foreignId('topic_id')->nullable()->after('writer_mode')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('topic_id');
            $table->dropColumn('writer_mode');
        });
    }
};
