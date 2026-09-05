<?php

namespace App\Models;

use App\Enums\CefrLevel;
use Illuminate\Database\Eloquent\Model;
use App\Enums\PartOfSpeech;


class Word extends Model
{
    protected $fillable = [
        'external_id', 'word', 'frequency_rank', 'part_of_speech',
        'homonym_index', 'translation_en', 'translation_ru', 'translation_uk', 'cefr_level',
        'gloss_en', 'gloss_ru', 'gloss_uk',
    ];

    protected $casts = [
        'part_of_speech' => PartOfSpeech::class,
        'cefr_level' => CefrLevel::class,
    ];

    public function noun(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Noun::class, 'external_word_id', 'external_id');
    }

    public function verb(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Verb::class, 'external_word_id', 'external_id');
    }

    public function adjective(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Adjective::class, 'external_word_id', 'external_id');
    }

    public function examples(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            Example::class,
            'example_word',
            'external_word_id',
            'external_example_id',
            'external_id',
            'external_id',
        );
    }

    public function groups(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            WordGroup::class,
            'group_word',
            'external_word_id',
            'external_word_group_id',
            'external_id',
            'external_id',
        );
    }
}
