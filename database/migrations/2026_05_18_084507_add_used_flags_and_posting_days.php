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
        foreach (['topics', 'social_posts', 'content_pieces'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('used')->default(false)->index();
            });
        }
        Schema::table('teams', function (Blueprint $t) {
            $t->json('posting_days')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['topics', 'social_posts', 'content_pieces'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('used');
            });
        }
        Schema::table('teams', function (Blueprint $t) {
            $t->dropColumn('posting_days');
        });
    }
};
