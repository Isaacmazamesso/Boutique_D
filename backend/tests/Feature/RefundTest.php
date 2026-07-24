<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_remboursement_partiel_reintegre_le_stock(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct(retail: 500, stockQty: 50);
        $sale    = $this->makeSaleViaApi($cashier, $product, qty: 3); // stock: 47

        Sanctum::actingAs($cashier);
        $response = $this->postJson("/api/sales/{$sale['id']}/refund", [
            'items'  => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
            'reason' => 'Produit défectueux',
        ]);

        $response->assertOk();
        $this->assertSame(1000, $response->json('data.amount'));
        $this->assertSame(49, $product->stock->fresh()->quantity);
    }

    public function test_quantite_superieure_au_restant_rejetee(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct();
        $sale    = $this->makeSaleViaApi($cashier, $product, qty: 2);

        Sanctum::actingAs($cashier);
        $this->postJson("/api/sales/{$sale['id']}/refund", [
            'items'  => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 5]],
            'reason' => 'Erreur',
        ])->assertStatus(422);
    }

    public function test_seuil_depasse_refuse_pour_caissier(): void
    {
        Setting::setValue('remboursement_max', 100);
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct(retail: 500);
        $sale    = $this->makeSaleViaApi($cashier, $product, qty: 2);

        Sanctum::actingAs($cashier);
        $this->postJson("/api/sales/{$sale['id']}/refund", [
            'items'  => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
            'reason' => 'Retour client',
        ])->assertStatus(403);
    }

    public function test_le_seuil_ne_bloque_pas_le_proprietaire(): void
    {
        Setting::setValue('remboursement_max', 100);
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500);
        $sale    = $this->makeSaleViaApi($owner, $product, qty: 2);

        Sanctum::actingAs($owner);
        $this->postJson("/api/sales/{$sale['id']}/refund", [
            'items'  => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
            'reason' => 'Retour client',
        ])->assertOk();
    }

    public function test_find_by_receipt_expose_la_quantite_remboursee(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct();
        $sale    = $this->makeSaleViaApi($cashier, $product, qty: 3);

        Sanctum::actingAs($cashier);
        $this->postJson("/api/sales/{$sale['id']}/refund", [
            'items'  => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
            'reason' => 'Casse',
        ])->assertOk();

        $data = $this->getJson('/api/sales/receipt?receipt_number=' . $sale['receipt_number'])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['items'][0]['refunded_quantity']);
    }
}
