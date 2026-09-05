<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordGroup extends Model
{
    public $timestamps = false;

    protected $table    = 'word_groups';
    protected $fillable = [
        'external_id', 'group_code',
        'name_en', 'name_ru', 'name_uk',
    ];
}
