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
        Schema::create('verb_prepositions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('external_word_id');
            $table->string('preposition');    // e.g., auf, für
            $table->string('noun_case', 10);  // Akk, Dat, Gen

            // Translation of the prepositional construction as a whole
            $table->string('translation_en')->nullable();
            $table->string('translation_ru')->nullable();
            $table->string('translation_uk')->nullable();

            $table->foreign('external_word_id')->references('external_id')->on('words')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verb_prepositions');
    }
};
