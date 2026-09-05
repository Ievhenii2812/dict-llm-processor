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
        Schema::create('example_word', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('external_word_id');
            $table->unsignedInteger('external_example_id');

            $table->unique(['external_word_id', 'external_example_id']);

            $table->foreign('external_word_id')->references('external_id')->on('words')->onDelete('cascade');
            $table->foreign('external_example_id')->references('external_id')->on('examples')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('example_word');
    }
};
