<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $fillable = ['sale_id', 'cashier_id', 'approved_by', 'amount', 'reason'];

    public function sale()       { return $this->belongsTo(Sale::class); }
    public function cashier()    { return $this->belongsTo(User::class, 'cashier_id'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }
    public function items()      { return $this->hasMany(RefundItem::class); }
}
