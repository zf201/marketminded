<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('homepage_url')->nullable()->after('powerful_model');
            $table->string('blog_url')->nullable()->after('homepage_url');
            $table->text('brand_description')->nullable()->after('blog_url');
            $table->jsonb('product_urls')->default('[]')->after('brand_description');
            $table->jsonb('competitor_urls')->default('[]')->after('product_urls');
            $table->jsonb('style_reference_urls')->default('[]')->after('competitor_urls');
            $table->text('target_audience')->nullable()->after('style_reference_urls');
            $table->string('tone_keywords')->nullable()->after('target_audience');
            $table->string('content_language', 50)->default('English')->after('tone_keywords');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'homepage_url',
                'blog_url',
                'brand_description',
                'product_urls',
                'competitor_urls',
                'style_reference_urls',
                'target_audience',
                'tone_keywords',
                'content_language',
            ]);
        });
    }
};
