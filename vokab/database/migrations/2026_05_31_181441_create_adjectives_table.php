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
        Schema::create('adjectives', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('external_word_id')->unique();
            $table->string('comparative')->nullable();
            $table->string('superlative')->nullable();
            $table->foreign('external_word_id')->references('external_id')->on('words')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adjectives');
    }
};
