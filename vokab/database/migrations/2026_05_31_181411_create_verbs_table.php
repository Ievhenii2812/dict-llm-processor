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
        Schema::create('verbs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('external_word_id')->unique();
            $table->boolean('is_regular')->default(true);
            $table->string('preterite')->nullable();       // Präteritum
            $table->string('participle_ii')->nullable();    // Partizip II
            $table->string('aux_verb', 10)->default('haben'); // haben / sein
            $table->foreign('external_word_id')->references('external_id')->on('words')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verbs');
    }
};
