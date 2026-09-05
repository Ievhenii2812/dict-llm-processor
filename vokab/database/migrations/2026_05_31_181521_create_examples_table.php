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
        Schema::create('examples', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('external_id')->unique();
            $table->string('sentence', 500)->unique(); // 500 chars allows safe UNIQUE index in MySQL

            /*
             * Source flag.
             * false = real corpus sentence (from Wiktionary Beispiele)
             * true  = AI-generated sentence (Stage 2, gap-filling via Ollama)
             * Used for quality control and future corpus revision.
             */
            $table->boolean('is_ai_generated')->default(false);

            // LibreTranslate-populated (Stage 1) or null on translation failure
            $table->text('translation_en')->nullable();
            $table->text('translation_ru')->nullable();
            $table->text('translation_uk')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('examples');
    }
};
