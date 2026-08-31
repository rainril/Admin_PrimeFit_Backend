<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Admin extends Model
{
    protected $fillable = [
        'account_id',
        'first_name',
        'last_name',
        'admin_level',
        'notifications_enabled',
        'dark_mode',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}