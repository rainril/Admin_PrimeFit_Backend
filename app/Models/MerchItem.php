<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchItem extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'price',
        'stock',
        'sold',
        'revenue',
        'image_url',
    ];

    public function sales()
    {
        return $this->hasMany(MerchSale::class, 'item_id');
    }
}
