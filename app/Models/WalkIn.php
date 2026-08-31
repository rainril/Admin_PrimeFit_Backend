<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalkIn extends Model
{
    protected $fillable = [
        'name',
        'date',
        'check_in',
        'check_out',
        'amount',
        'method',
        'status',
        'handled_by_admin_id',
    ];
}