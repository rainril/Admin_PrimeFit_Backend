<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'duration_label',
        'duration_months',
        'price',
        'discount_percent',
        'features',
    ];

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }
}