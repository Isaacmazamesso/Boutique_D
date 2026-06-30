<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
    protected $fillable = [
        'product_id', 'purchase_price', 'retail_price',
        'wholesale_price', 'wholesale_min_qty',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
