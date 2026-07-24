<?php

namespace Tests\Feature;

use App\Models\PriceHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class BulkPriceUpdateTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_ajustement_pourcentage_applique_au_prix_detail(): void
    {
        $owner = $this->makeUser('proprietaire');
        $p1    = $this->makeProduct(retail: 500);
        $p2    = $this->makeProduct(retail: 1000);

        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [$p1->id, $p2->id],
            'field'            => 'retail_price',
            'adjustment_type'  => 'percent',
            'adjustment_value' => 10,
        ]);

        $response->assertOk();
        $this->assertSame(550, $p1->price->fresh()->retail_price);
        $this->assertSame(1100, $p2->price->fresh()->retail_price);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame(550, collect($data)->firstWhere('id', $p1->id)['new']);
    }

    public function test_ajustement_montant_fixe_applique_au_prix_gros(): void
    {
        $owner = $this->makeUser('proprietaire');
        $p1    = $this->makeProduct(retail: 500); // wholesale = retail - 100 = 400 (voir CreatesShopData::makeProduct)

        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [$p1->id],
            'field'            => 'wholesale_price',
            'adjustment_type'  => 'fixed',
            'adjustment_value' => -50,
        ]);

        $response->assertOk();
        $this->assertSame(350, $p1->price->fresh()->wholesale_price);
    }

    public function test_prix_negatif_rejette_sans_rien_appliquer(): void
    {
        $owner = $this->makeUser('proprietaire');
        $p1    = $this->makeProduct(retail: 500);
        $p2    = $this->makeProduct(retail: 100);

        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [$p1->id, $p2->id],
            'field'            => 'retail_price',
            'adjustment_type'  => 'fixed',
            'adjustment_value' => -200, // p2 : 100 - 200 = -100 (invalide)
        ]);

        $response->assertStatus(422);
        $this->assertSame(500, $p1->price->fresh()->retail_price, 'aucun produit ne doit être modifié si un seul calcul échoue');
        $this->assertSame(100, $p2->price->fresh()->retail_price);
        $this->assertSame(0, PriceHistory::count());
    }

    public function test_necessite_le_role_proprietaire(): void
    {
        $cashier = $this->makeUser('caissier');
        $p1      = $this->makeProduct();

        Sanctum::actingAs($cashier);
        $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [$p1->id],
            'field'            => 'retail_price',
            'adjustment_type'  => 'percent',
            'adjustment_value' => 5,
        ])->assertForbidden();
    }

    public function test_l_historique_de_prix_trace_la_raison(): void
    {
        $owner = $this->makeUser('proprietaire');
        $p1    = $this->makeProduct(retail: 500);

        Sanctum::actingAs($owner);
        $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [$p1->id],
            'field'            => 'retail_price',
            'adjustment_type'  => 'percent',
            'adjustment_value' => 10,
            'reason'           => 'Hausse fournisseur',
        ])->assertOk();

        $entry = PriceHistory::where('product_id', $p1->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame('Hausse fournisseur', $entry->reason);
        $this->assertSame(500, $entry->old_retail_price);
        $this->assertSame(550, $entry->new_retail_price);
        $this->assertSame($entry->old_purchase_price, $entry->new_purchase_price, 'le prix d\'achat n\'est jamais modifié par ce module');
    }

    public function test_produit_inexistant_rejette_422(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [999999],
            'field'            => 'retail_price',
            'adjustment_type'  => 'percent',
            'adjustment_value' => 5,
        ])->assertStatus(422);
    }
}
