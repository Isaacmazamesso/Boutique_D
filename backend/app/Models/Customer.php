<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Customer extends Model
{
    protected $fillable = ['name', 'phone', 'note'];

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * True tant que la colonne sales.customer_id n'existe pas (ajoutee en Task 2) :
     * aucune vente ne peut reference un client, la relation est donc vide.
     */
    public static function salesLinked(): bool
    {
        return Schema::hasColumn('sales', 'customer_id');
    }

    public function hasSales(): bool
    {
        return self::salesLinked() && $this->sales()->exists();
    }
}
