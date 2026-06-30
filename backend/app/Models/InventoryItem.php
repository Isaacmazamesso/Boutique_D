<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    protected $fillable = [
        'inventory_id', 'product_id',
        'theoretical_qty', 'counted_qty', 'difference',
    ];

    public function inventory() { return $this->belongsTo(Inventory::class); }
    public function product()   { return $this->belongsTo(Product::class); }
}
