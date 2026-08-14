<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupSequence extends Model
{
    protected $primaryKey = 'center_id';
    public $incrementing = false;
    protected $fillable = ['center_id', 'last_number'];
}
