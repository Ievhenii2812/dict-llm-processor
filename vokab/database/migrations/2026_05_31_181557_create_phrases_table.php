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
        Schema::create('phrases', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('external_id')->unique();
            $table->text('phrase'); // Uniqueness enforced in PHP before insert

            // Academic German definition from Wiktionary (Stage 1)
            $table->text('meaning_de')->nullable();

            // AI-generated plain-language explanations (Stage 2, Ollama)
            $table->text('meaning_en')->nullable();
            $table->text('meaning_ru')->nullable();
            $table->text('meaning_uk')->nullable();

            // Literary analogues in target languages (Stage 2, Ollama)
            $table->text('translation_en')->nullable();
            $table->text('translation_ru')->nullable();
            $table->text('translation_uk')->nullable();

            $table->text('situation_question')->nullable(); // For advanced scenario-based testing
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phrases');
    }
};
