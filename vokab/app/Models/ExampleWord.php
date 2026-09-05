<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExampleWord extends Model
{
    public $timestamps = false;

    protected $table    = 'example_word';
    protected $fillable = ['external_word_id', 'external_example_id'];
}
