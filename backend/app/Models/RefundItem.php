<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefundItem extends Model
{
    protected $fillable = ['refund_id', 'product_id', 'quantity', 'amount'];

    public function refund()  { return $this->belongsTo(Refund::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
