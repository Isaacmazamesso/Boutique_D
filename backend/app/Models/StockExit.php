<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockExit extends Model
{
    protected $fillable = [
        'product_id', 'created_by', 'approved_by',
        'quantity', 'reason', 'notes',
    ];

    public function product()    { return $this->belongsTo(Product::class); }
    public function createdBy()  { return $this->belongsTo(User::class, 'created_by'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }
}
