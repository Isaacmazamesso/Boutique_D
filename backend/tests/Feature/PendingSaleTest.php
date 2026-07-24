<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class PendingSaleTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_un_vendeur_peut_creer_un_panier_en_attente(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 50);

        Sanctum::actingAs($vendeur);
        $response = $this->postJson('/api/sales/pending', [
            'items'     => [['product_id' => $product->id, 'quantity' => 3]],
            'sale_type' => 'detail',
        ]);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertSame('en_attente', $data['status']);
        $this->assertSame(1500, $data['total']);
        $this->assertNull($data['cashier']);
        $this->assertSame($vendeur->name, $data['vendor']);
        $this->assertSame(47, $product->stock->fresh()->quantity, 'le stock doit etre reserve des la creation');
    }

    public function test_stock_insuffisant_rejette_la_creation(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(stockQty: 2);

        Sanctum::actingAs($vendeur);
        $this->postJson('/api/sales/pending', [
            'items'     => [['product_id' => $product->id, 'quantity' => 5]],
            'sale_type' => 'detail',
        ])->assertStatus(422);

        $this->assertSame(2, $product->stock->fresh()->quantity);
    }

    public function test_remise_excessive_refusee_pour_un_vendeur(): void
    {
        Setting::setValue('remise_max_sans_auth', 10);
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 1000, stockQty: 10);

        Sanctum::actingAs($vendeur);
        $this->postJson('/api/sales/pending', [
            'items'          => [['product_id' => $product->id, 'quantity' => 1]],
            'sale_type'      => 'detail',
            'discount_type'  => 'percent',
            'discount_value' => 50,
        ])->assertStatus(403);
    }
}
