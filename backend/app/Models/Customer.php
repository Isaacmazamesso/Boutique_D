<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name', 'phone', 'note'];

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function hasSales(): bool
    {
        return $this->sales()->exists();
    }
}
