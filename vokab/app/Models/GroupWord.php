<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupWord extends Model
{
    public $timestamps = false;

    protected $table    = 'group_word';
    protected $fillable = [
        'external_word_id',
        'external_word_group_id',
    ];
}
