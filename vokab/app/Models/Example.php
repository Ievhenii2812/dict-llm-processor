<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Example extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'external_id', 'sentence', 'is_ai_generated',
        'translation_en', 'translation_ru', 'translation_uk',
    ];

    protected $casts = [
        'is_ai_generated' => 'boolean',
    ];

    public function words(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            Word::class,
            'example_word',
            'external_example_id',
            'external_word_id',
            'external_id',
            'external_id',
        );
    }
}
