<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'receipt_number', 'cashier_id', 'vendor_id', 'cash_session_id',
        'sale_type', 'payment_method', 'mobile_money_number',
        'subtotal', 'discount_type', 'discount_value', 'total',
        'amount_paid', 'change_given', 'notes',
    ];

    public function cashier() { return $this->belongsTo(User::class, 'cashier_id'); }
    public function vendor()  { return $this->belongsTo(User::class, 'vendor_id'); }
    public function items()   { return $this->hasMany(SaleItem::class); }
    public function refunds() { return $this->hasMany(Refund::class); }
}
