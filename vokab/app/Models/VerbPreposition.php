<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class VerbPreposition extends Model
{
    public $timestamps = false;

    protected $table    = 'verb_prepositions';
    protected $fillable = [
        'external_word_id', 'preposition', 'noun_case',
        'translation_en', 'translation_ru', 'translation_uk',
    ];
}
