<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Noun extends Model
{
    public $timestamps = false;

    protected $fillable = ['external_word_id', 'gender', 'plural'];
}
