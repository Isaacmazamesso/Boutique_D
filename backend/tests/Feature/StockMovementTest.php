<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_entree_incremente_le_stock(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, purchase: 300, stockQty: 10);

        Sanctum::actingAs($gestionnaire);
        $response = $this->postJson('/api/stock/entries', [
            'product_id' => $product->id, 'quantity' => 20, 'purchase_price' => 300,
        ]);

        $response->assertCreated();
        $this->assertSame(30, $product->stock->fresh()->quantity);
    }

    public function test_entree_met_a_jour_le_prix_achat(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, purchase: 300, stockQty: 10);

        Sanctum::actingAs($gestionnaire);
        $this->postJson('/api/stock/entries', [
            'product_id' => $product->id, 'quantity' => 5, 'purchase_price' => 350,
        ])->assertCreated();

        $this->assertSame(350, $product->price->fresh()->purchase_price);
    }

    public function test_sortie_decremente_le_stock(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, stockQty: 30);

        Sanctum::actingAs($gestionnaire);
        $this->postJson('/api/stock/exits', [
            'product_id' => $product->id, 'quantity' => 5, 'reason' => 'casse',
        ])->assertCreated();

        $this->assertSame(25, $product->stock->fresh()->quantity);
    }

    public function test_sortie_stock_insuffisant_refuse(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, stockQty: 3);

        Sanctum::actingAs($gestionnaire);
        $this->postJson('/api/stock/exits', [
            'product_id' => $product->id, 'quantity' => 10, 'reason' => 'casse',
        ])->assertStatus(422);

        $this->assertSame(3, $product->stock->fresh()->quantity);
    }

    public function test_sortie_au_dela_du_seuil_refusee_pour_gestionnaire(): void
    {
        Setting::setValue('sortie_stock_max', 20);
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($gestionnaire);
        $this->postJson('/api/stock/exits', [
            'product_id' => $product->id, 'quantity' => 25, 'reason' => 'perte',
        ])->assertStatus(403);
    }

    public function test_sortie_au_dela_du_seuil_autorisee_pour_proprietaire(): void
    {
        Setting::setValue('sortie_stock_max', 20);
        $owner = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($owner);
        $this->postJson('/api/stock/exits', [
            'product_id' => $product->id, 'quantity' => 25, 'reason' => 'perte',
        ])->assertCreated();

        $this->assertSame(75, $product->stock->fresh()->quantity);
    }

    public function test_motif_de_sortie_invalide_refuse(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($gestionnaire);
        $this->postJson('/api/stock/exits', [
            'product_id' => $product->id, 'quantity' => 5, 'reason' => 'motif_bidon',
        ])->assertStatus(422);
    }

    public function test_alertes_listent_stock_bas_et_rupture(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $this->makeProduct(retail: 500, stockQty: 3);  // min_stock_alert=5 → stock_bas
        $this->makeProduct(retail: 500, stockQty: 0);  // rupture

        Sanctum::actingAs($gestionnaire);
        $response = $this->getJson('/api/stock/alerts');

        $response->assertOk();
        $types = collect($response->json('data.alerts'))->pluck('type');
        $this->assertTrue($types->contains('stock_bas'));
        $this->assertTrue($types->contains('rupture'));
    }
}
