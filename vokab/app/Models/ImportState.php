<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class ImportState extends Model
{
    protected $table      = 'import_state';
    protected $primaryKey = 'key';
    public    $incrementing = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'value'];
}
