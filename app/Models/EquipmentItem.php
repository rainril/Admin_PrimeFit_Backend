<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentItem extends Model
{
    protected $fillable = [
        'barcode',
        'name',
        'category',
        'qty',
        'status',
        'location',
        'next_maintenance',
        'description',
        'image_url',
    ];

    protected $casts = [
        'next_maintenance' => 'date',
    ];
}
