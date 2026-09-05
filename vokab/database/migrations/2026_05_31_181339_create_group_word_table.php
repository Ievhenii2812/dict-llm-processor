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
        Schema::create('group_word', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('external_word_id');
            $table->unsignedInteger('external_word_group_id');
            $table->foreign('external_word_id')->references('external_id')->on('words')->onDelete('cascade');
            $table->foreign('external_word_group_id')->references('external_id')->on('word_groups')->onDelete('cascade');
            $table->unique(['external_word_id', 'external_word_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_word');
    }
};
