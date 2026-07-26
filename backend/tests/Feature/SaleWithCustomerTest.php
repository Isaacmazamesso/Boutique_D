<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class SaleWithCustomerTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_vente_rattachee_a_un_client(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);
        $customer = Customer::create(['name' => 'Awa Diop', 'phone' => '770000010']);

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 1000,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'customer_id' => $customer->id,
        ]);

        $response->assertCreated();
        $this->assertSame('Awa Diop', $response->json('data.customer'));
        $this->assertSame($customer->id, Sale::first()->customer_id);
    }

    public function test_vente_anonyme_inchangee(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 500,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertCreated();
        $this->assertNull(Sale::first()->customer_id);
        $this->assertNull($response->json('data.customer'));
    }

    public function test_customer_id_inexistant_refuse(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 500,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'customer_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_fiche_client_liste_ses_ventes_et_total(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);
        $customer = Customer::create(['name' => 'Client Fidele', 'phone' => '770000011']);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 1000,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'customer_id' => $customer->id,
        ])->assertCreated();

        $gestionnaire = $this->makeUser('gestionnaire');
        Sanctum::actingAs($gestionnaire);
        $data = $this->getJson("/api/customers/{$customer->id}")->assertOk()->json('data');

        $this->assertSame(1, $data['nb_ventes']);
        $this->assertSame(1000, $data['total_depense']);
        $this->assertCount(1, $data['ventes']);
    }

    public function test_validation_panier_en_attente_rattache_le_client(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $customer = Customer::create(['name' => 'Client Comptoir', 'phone' => '770000012']);

        Sanctum::actingAs($vendeur);
        $sale = $this->postJson('/api/sales/pending', [
            'items'     => [['product_id' => $product->id, 'quantity' => 2]],
            'sale_type' => 'detail',
        ])->json('data');

        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        Sanctum::actingAs($cashier);
        $response = $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes',
            'amount_paid'    => 1000,
            'customer_id'    => $customer->id,
        ]);

        $response->assertOk();
        $this->assertSame('Client Comptoir', $response->json('data.customer'));
        $this->assertSame($customer->id, Sale::find($sale['id'])->customer_id);
    }

    public function test_validation_panier_en_attente_sans_client_reste_anonyme(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);

        Sanctum::actingAs($vendeur);
        $sale = $this->postJson('/api/sales/pending', [
            'items'     => [['product_id' => $product->id, 'quantity' => 2]],
            'sale_type' => 'detail',
        ])->json('data');

        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        Sanctum::actingAs($cashier);
        $response = $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes',
            'amount_paid'    => 1000,
        ]);

        $response->assertOk();
        $this->assertNull($response->json('data.customer'));
        $this->assertNull(Sale::find($sale['id'])->customer_id);
    }
}
