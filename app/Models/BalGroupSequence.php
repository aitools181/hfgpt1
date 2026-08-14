<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BalGroupSequence extends Model
{
    protected $fillable = ['center_id', 'last_number'];
    protected $primaryKey = 'center_id';
    public $incrementing = false;
}
