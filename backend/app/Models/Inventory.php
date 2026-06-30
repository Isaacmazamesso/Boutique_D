<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = [
        'name', 'type', 'category_id', 'status',
        'created_by', 'validated_by', 'validated_at',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
    ];

    public function category()    { return $this->belongsTo(Category::class); }
    public function createdBy()   { return $this->belongsTo(User::class, 'created_by'); }
    public function validatedBy() { return $this->belongsTo(User::class, 'validated_by'); }
    public function items()       { return $this->hasMany(InventoryItem::class); }

    public function totalDifference(): int
    {
        return $this->items->sum('difference');
    }

    public function totalValueDifference(): int
    {
        return $this->items->sum(fn($i) =>
            ($i->difference ?? 0) * ($i->product->price?->purchase_price ?? 0)
        );
    }
}
