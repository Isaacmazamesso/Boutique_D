<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class SaleStoreTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_stock_insuffisant_refuse(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 3);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 5000,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ])->assertStatus(422);

        $this->assertSame(3, $product->stock->fresh()->quantity, 'le stock ne doit pas bouger sur un refus');
    }

    public function test_quantite_gros_sous_le_minimum_bascule_au_detail_avec_alerte(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100); // wholesale_min_qty = 12

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', [
            'sale_type' => 'gros', 'payment_method' => 'especes', 'amount_paid' => 5000,
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]);

        // Le cahier des charges (module 4.1) demande un basculement automatique au prix
        // détail avec simple alerte, pas un blocage de la vente.
        $response->assertCreated();
        $this->assertSame(2500, $response->json('data.total'), '5 x prix detail 500 = 2500 (pas le prix gros)');
        $this->assertNotEmpty($response->json('data.price_warnings'));
    }

    public function test_prix_gros_applique_au_dela_du_minimum(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100); // wholesale_price = 400, min 12

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', [
            'sale_type' => 'gros', 'payment_method' => 'especes', 'amount_paid' => 100000,
            'items' => [['product_id' => $product->id, 'quantity' => 12]],
        ]);

        $response->assertCreated();
        $this->assertSame(4800, $response->json('data.total'), '12 x prix gros 400 = 4800');
    }

    public function test_remise_excessive_refusee_pour_caissier(): void
    {
        Setting::setValue('remise_max_sans_auth', 10);
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 1000, stockQty: 100);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 100000,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'discount_type' => 'percent', 'discount_value' => 50,
        ])->assertStatus(403);
    }

    public function test_remise_excessive_autorisee_pour_proprietaire(): void
    {
        Setting::setValue('remise_max_sans_auth', 10);
        $owner = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 1000, stockQty: 100);

        Sanctum::actingAs($owner);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 100000,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'discount_type' => 'percent', 'discount_value' => 50,
        ])->assertCreated();
    }

    public function test_montant_especes_insuffisant_refuse(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 100,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_caissier_sans_session_refuse(): void
    {
        $cashier = $this->makeUser('caissier'); // pas de session ouverte
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 500,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_monnaie_rendue_calculee(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 1000,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertCreated();
        $this->assertSame(500, $response->json('data.total'));
        $this->assertSame(500, $response->json('data.change_given'), '1000 recu - 500 du = 500 rendu');
    }
}
