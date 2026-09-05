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
        Schema::table('words', function (Blueprint $table) {
            Schema::table('words', function (Blueprint $table) {
                $table->text('gloss_en')->nullable()->after('translation_uk');
                $table->text('gloss_ru')->nullable()->after('gloss_en');
                $table->text('gloss_uk')->nullable()->after('gloss_ru');
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            Schema::table('words', function (Blueprint $table) {
                $table->dropColumn(['gloss_en', 'gloss_ru', 'gloss_uk']);
            });
        });
    }
};
