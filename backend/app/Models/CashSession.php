<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashSession extends Model
{
    protected $fillable = [
        'cashier_id', 'opening_amount', 'closing_amount',
        'theoretical_amount', 'difference', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function cashier() { return $this->belongsTo(User::class, 'cashier_id'); }
    public function sales()   { return $this->hasMany(Sale::class); }

    public function isOpen(): bool
    {
        return is_null($this->closed_at);
    }

    public function totalSales(): int
    {
        return $this->sales()->sum('total');
    }

    public function totalRefunds(): int
    {
        return Refund::whereIn('sale_id', $this->sales()->pluck('id'))->sum('amount');
    }

    public function theoreticalAmount(): int
    {
        return $this->opening_amount + $this->totalSales() - $this->totalRefunds();
    }
}
