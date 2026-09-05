<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Phrase extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'external_id', 'phrase', 'meaning_de',
        'meaning_en', 'meaning_ru', 'meaning_uk',
        'translation_en', 'translation_ru', 'translation_uk',
        'situation_question',
    ];
}
