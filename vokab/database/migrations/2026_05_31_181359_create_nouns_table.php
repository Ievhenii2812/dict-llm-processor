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
        Schema::create('nouns', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('external_word_id')->unique();
            $table->string('gender', 10);  // m, f, n
            $table->string('plural');      // e.g., -en, die Tische
            $table->foreign('external_word_id')->references('external_id')->on('words')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nouns');
    }
};
