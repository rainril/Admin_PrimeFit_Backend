<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'customer_id',
        'membership_id',
        'date',
        'amount',
        'method',
        'status',
        'source_id',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function membership()
    {
        return $this->belongsTo(Membership::class);
    }
}