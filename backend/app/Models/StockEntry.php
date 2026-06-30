<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockEntry extends Model
{
    protected $fillable = [
        'product_id', 'received_by', 'quantity', 'purchase_price',
        'supplier', 'invoice_number', 'expiry_date', 'notes',
    ];

    public function product()     { return $this->belongsTo(Product::class); }
    public function receivedBy()  { return $this->belongsTo(User::class, 'received_by'); }
}
