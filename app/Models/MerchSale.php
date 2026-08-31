<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchSale extends Model
{
    protected $fillable = [
        'item_id',
        'item_name',
        'quantity',
        'unit_price',
        'total_amount',
        'buyer_name',
        'payment_method',
        'status',
        'recorded_by_admin_id',
        'date',
        'confirmed_at',
    ];

    protected $casts = [
        'date' => 'date',
        'confirmed_at' => 'datetime',
    ];

    // ASSUMPTION: your merch/inventory item model is called `MerchItem`.
    // If it's actually named something else (InventoryItem, Product, etc.),
    // rename the class reference below to match.
    public function item()
    {
        return $this->belongsTo(MerchItem::class, 'item_id');
    }
}
