<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Adjective extends Model
{
    public $timestamps = false;

    protected $fillable = ['external_word_id', 'comparative', 'superlative'];
}
