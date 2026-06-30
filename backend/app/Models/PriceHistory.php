<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    protected $table = 'price_history';

    protected $fillable = [
        'product_id', 'changed_by',
        'old_purchase_price', 'new_purchase_price',
        'old_retail_price', 'new_retail_price',
        'old_wholesale_price', 'new_wholesale_price',
        'reason',
    ];

    public function product()   { return $this->belongsTo(Product::class); }
    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
}
