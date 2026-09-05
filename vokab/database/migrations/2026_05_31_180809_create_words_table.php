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
        Schema::create('words', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('external_id')->unique(); // Sequential ID for data portability (NOT Leipzig line number)
            $table->string('word')->index();
            $table->unsignedInteger('frequency_rank')->nullable();
            $table->unsignedTinyInteger('part_of_speech'); // PartOfSpeech Enum value

            /*
             * Homonym disambiguator.
             * 1, 2, 3... for words sharing the same string (e.g., der Kiefer / die Kiefer).
             * NULL for words with no homonyms.
             */
            $table->unsignedTinyInteger('homonym_index')->nullable();

            // AI-populated translation fields (filled in Stage 2)
            $table->string('translation_en')->nullable();
            $table->string('translation_ru')->nullable();
            $table->string('translation_uk')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('words');
    }
};
