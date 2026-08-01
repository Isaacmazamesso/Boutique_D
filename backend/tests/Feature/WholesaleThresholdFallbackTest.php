<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class WholesaleThresholdFallbackTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_vente_en_attente_sous_le_seuil_gros_bascule_au_detail(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 100); // wholesale_min_qty = 12

        Sanctum::actingAs($vendeur);
        $response = $this->postJson('/api/sales/pending', [
            'sale_type' => 'gros',
            'items'     => [['product_id' => $product->id, 'quantity' => 3]],
        ]);

        $response->assertCreated();
        $this->assertSame(1500, $response->json('data.total'), '3 x prix detail 500 = 1500');
        $this->assertNotEmpty($response->json('data.price_warnings'));
        $this->assertSame(500, $response->json('data.items.0.unit_price'));
    }

    public function test_vente_en_attente_au_dela_du_seuil_applique_le_prix_gros_sans_alerte(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 100); // wholesale_price = 400, min 12

        Sanctum::actingAs($vendeur);
        $response = $this->postJson('/api/sales/pending', [
            'sale_type' => 'gros',
            'items'     => [['product_id' => $product->id, 'quantity' => 20]],
        ]);

        $response->assertCreated();
        $this->assertSame(8000, $response->json('data.total'), '20 x prix gros 400 = 8000');
        $this->assertEmpty($response->json('data.price_warnings'));
    }

    public function test_validation_a_la_caisse_avec_nouveaux_articles_sous_le_seuil_bascule_au_detail(): void
    {
        $vendeur  = $this->makeUser('vendeur');
        $cashier  = $this->makeUser('caissier');
        $product  = $this->makeProduct(retail: 500, stockQty: 100);
        $this->openSession($cashier);

        Sanctum::actingAs($vendeur);
        $pending = $this->postJson('/api/sales/pending', [
            'sale_type' => 'gros',
            'items'     => [['product_id' => $product->id, 'quantity' => 20]],
        ])->json('data');

        Sanctum::actingAs($cashier);
        $response = $this->postJson("/api/sales/{$pending['id']}/validate", [
            'sale_type'      => 'gros',
            'payment_method' => 'especes',
            'amount_paid'    => 5000,
            'items'          => [['product_id' => $product->id, 'quantity' => 4]], // sous le seuil de 12
        ]);

        $response->assertOk();
        $this->assertSame(2000, $response->json('data.total'), '4 x prix detail 500 = 2000');
        $this->assertNotEmpty($response->json('data.price_warnings'));
    }
}
