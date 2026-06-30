<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'category_id', 'name', 'unit', 'barcode', 'photo',
        'brand', 'supplier', 'expiry_date', 'min_stock_alert',
        'location', 'is_active',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'expiry_date' => 'date',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function price()
    {
        return $this->hasOne(ProductPrice::class);
    }

    public function stock()
    {
        return $this->hasOne(Stock::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function stockEntries()
    {
        return $this->hasMany(StockEntry::class);
    }

    public function stockExits()
    {
        return $this->hasMany(StockExit::class);
    }

    public function priceHistory()
    {
        return $this->hasMany(PriceHistory::class);
    }

    public function hasSales(): bool
    {
        return $this->saleItems()->exists();
    }

    public function currentStock(): int
    {
        return $this->stock?->quantity ?? 0;
    }

    public function stockStatus(): string
    {
        $qty = $this->currentStock();
        if ($qty <= 0) return 'rupture';
        if ($qty <= $this->min_stock_alert) return 'bas';
        return 'normal';
    }
}
