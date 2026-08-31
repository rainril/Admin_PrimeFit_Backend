<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Account extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'email',
        'password',
        'phone',
        'role',
        'customer_id',
        'reset_code',
        'reset_code_expires_at',
    ];

    protected $hidden = [
        'password',
        'reset_code',
    ];

    public function admin()
    {
        return $this->hasOne(Admin::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}