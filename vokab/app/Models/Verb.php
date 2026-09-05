<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Verb extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'external_word_id', 'is_regular',
        'preterite', 'participle_ii', 'aux_verb',
    ];

    protected $casts = [
        'is_regular' => 'boolean',
    ];

    public function prepositions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VerbPreposition::class, 'external_word_id', 'external_word_id');
    }
}
