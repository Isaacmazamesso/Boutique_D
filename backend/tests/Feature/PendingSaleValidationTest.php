<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class PendingSaleValidationTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    private function makePendingSale($vendeur, $product, int $qty = 2): array
    {
        Sanctum::actingAs($vendeur);
        $response = $this->postJson('/api/sales/pending', [
            'items'     => [['product_id' => $product->id, 'quantity' => $qty]],
            'sale_type' => 'detail',
        ]);

        return $response->json('data');
    }

    public function test_la_liste_des_ventes_en_attente_est_visible(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product);

        $cashier = $this->makeUser('caissier');
        Sanctum::actingAs($cashier);
        $response = $this->getJson('/api/sales/pending');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($sale['id'], $response->json('data')[0]['id']);
    }

    public function test_le_caissier_valide_un_panier_tel_quel(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 3); // stock: 17

        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        Sanctum::actingAs($cashier);

        $response = $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes',
            'amount_paid'    => 1500,
        ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('validee', $data['status']);
        $this->assertSame($cashier->name, $data['cashier']);
        $this->assertSame(17, $product->stock->fresh()->quantity, 'le stock ne doit pas rechanger si les articles ne sont pas modifies');
    }

    public function test_le_caissier_peut_modifier_les_articles_avant_encaissement(): void
    {
        $vendeur  = $this->makeUser('vendeur');
        $product1 = $this->makeProduct(retail: 500, stockQty: 20);
        $product2 = $this->makeProduct(retail: 300, stockQty: 20);
        $sale     = $this->makePendingSale($vendeur, $product1, qty: 3); // stock product1: 17

        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        Sanctum::actingAs($cashier);

        $response = $this->postJson("/api/sales/{$sale['id']}/validate", [
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 1],
                ['product_id' => $product2->id, 'quantity' => 2],
            ],
            'sale_type'      => 'detail',
            'payment_method' => 'especes',
            'amount_paid'    => 1100,
        ]);

        $response->assertOk();
        $this->assertSame(1100, $response->json('data.total'));
        $this->assertSame(19, $product1->stock->fresh()->quantity, '17 restitue a 20 puis redecompte de 1 = 19');
        $this->assertSame(18, $product2->stock->fresh()->quantity, '20 - 2 = 18');
    }

    public function test_montant_insuffisant_en_especes_rejette_la_validation(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 2);

        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        Sanctum::actingAs($cashier);

        $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes',
            'amount_paid'    => 500,
        ])->assertStatus(422);
    }

    public function test_impossible_de_valider_deux_fois(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 2);

        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        Sanctum::actingAs($cashier);

        $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes', 'amount_paid' => 1000,
        ])->assertOk();

        $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes', 'amount_paid' => 1000,
        ])->assertStatus(422);
    }

    public function test_annulation_restitue_le_stock(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 4); // stock: 16

        Sanctum::actingAs($vendeur);
        $response = $this->postJson("/api/sales/{$sale['id']}/cancel-pending");

        $response->assertOk();
        $this->assertSame(20, $product->stock->fresh()->quantity);
    }

    public function test_un_caissier_peut_aussi_annuler(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 2);

        $cashier = $this->makeUser('caissier');
        Sanctum::actingAs($cashier);
        $this->postJson("/api/sales/{$sale['id']}/cancel-pending")->assertOk();
    }

    public function test_caissier_sans_session_ne_peut_pas_valider(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 2);

        $cashier = $this->makeUser('caissier'); // pas de session ouverte
        Sanctum::actingAs($cashier);

        $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes', 'amount_paid' => 1000,
        ])->assertStatus(422);
    }
}
