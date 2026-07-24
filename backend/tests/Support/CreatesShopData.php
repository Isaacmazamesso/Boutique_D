<?php

namespace Tests\Support;

use App\Models\CashSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Stock;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

trait CreatesShopData
{
    protected function seedRoles(): void
    {
        foreach (['proprietaire', 'gestionnaire', 'caissier', 'vendeur'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    protected function makeUser(string $role, ?string $username = null): User
    {
        $this->seedRoles();
        $user = User::create([
            'name'      => ucfirst($role) . ' Test',
            'username'  => $username ?? $role . '_' . uniqid(),
            'password'  => 'secret123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function makeProduct(int $retail = 500, int $purchase = 300, int $stockQty = 100): Product
    {
        $category = Category::firstOrCreate(['name' => 'Test Cat']);
        $product  = Product::create([
            'category_id'     => $category->id,
            'name'            => 'Produit ' . uniqid(),
            'unit'            => 'piece',
            'min_stock_alert' => 5,
            'is_active'       => true,
        ]);
        ProductPrice::create([
            'product_id'        => $product->id,
            'purchase_price'    => $purchase,
            'retail_price'      => $retail,
            'wholesale_price'   => $retail - 100,
            'wholesale_min_qty' => 12,
        ]);
        Stock::create(['product_id' => $product->id, 'quantity' => $stockQty]);

        return $product;
    }

    protected function openSession(User $cashier): CashSession
    {
        return CashSession::create([
            'cashier_id'     => $cashier->id,
            'opening_amount' => 10000,
            'opened_at'      => now(),
        ]);
    }

    protected function makeSaleViaApi(User $cashier, Product $product, int $qty = 2): array
    {
        if (!CashSession::where('cashier_id', $cashier->id)->whereNull('closed_at')->exists()) {
            $this->openSession($cashier);
        }
        Sanctum::actingAs($cashier);

        $retail = $product->price->retail_price;
        $response = $this->postJson('/api/sales', [
            'sale_type'      => 'detail',
            'payment_method' => 'especes',
            'items'          => [['product_id' => $product->id, 'quantity' => $qty]],
            'amount_paid'    => $retail * $qty,
        ]);
        $response->assertCreated();

        return $response->json('data');
    }
}
