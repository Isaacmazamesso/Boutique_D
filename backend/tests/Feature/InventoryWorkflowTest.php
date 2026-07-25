<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class InventoryWorkflowTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_creation_snapshot_le_stock_theorique(): void
    {
        $owner = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500, stockQty: 42);

        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/inventories', ['name' => 'Inv Jan', 'type' => 'complet']);

        $response->assertCreated();
        // Ajustement : formatInventory() est appelé sans detailed=true dans store(),
        // donc data.items est absent de la reponse de creation. On recupere le detail via show().
        $inventoryId = $response->json('data.id');
        $items = $this->getJson("/api/inventories/{$inventoryId}")->json('data.items');
        $line = collect($items)->firstWhere('product_id', $product->id);
        $this->assertSame(42, $line['theoretical_qty']);
    }

    public function test_un_seul_inventaire_en_cours(): void
    {
        $owner = $this->makeUser('proprietaire');
        $this->makeProduct(retail: 500, stockQty: 10);

        Sanctum::actingAs($owner);
        $this->postJson('/api/inventories', ['name' => 'Inv 1', 'type' => 'complet'])->assertCreated();
        $this->postJson('/api/inventories', ['name' => 'Inv 2', 'type' => 'complet'])->assertStatus(422);
    }

    public function test_comptage_calcule_l_ecart(): void
    {
        $owner = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500, stockQty: 20);

        Sanctum::actingAs($owner);
        $invId = $this->postJson('/api/inventories', ['name' => 'Inv', 'type' => 'complet'])->json('data.id');
        // Ajustement : items absent de la reponse de creation (voir test precedent) -> on passe par show().
        $items = $this->getJson("/api/inventories/{$invId}")->json('data.items');
        $itemId = collect($items)->firstWhere('product_id', $product->id)['id'];

        $this->postJson("/api/inventories/{$invId}/count", [
            'items' => [['inventory_item_id' => $itemId, 'counted_qty' => 18]],
        ])->assertOk();

        $detail = $this->getJson("/api/inventories/{$invId}")->json('data');
        $line = collect($detail['items'])->firstWhere('id', $itemId);
        $this->assertSame(18, $line['counted_qty']);
        $this->assertSame(-2, $line['difference'], '18 comptes - 20 theoriques = -2');
    }

    public function test_validation_ajuste_le_stock(): void
    {
        $owner = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500, stockQty: 20);

        Sanctum::actingAs($owner);
        $invId = $this->postJson('/api/inventories', ['name' => 'Inv', 'type' => 'complet'])->json('data.id');
        $items = $this->getJson("/api/inventories/{$invId}")->json('data.items');
        $itemId = collect($items)->firstWhere('product_id', $product->id)['id'];

        $this->postJson("/api/inventories/{$invId}/count", [
            'items' => [['inventory_item_id' => $itemId, 'counted_qty' => 18]],
        ])->assertOk();

        $this->postJson("/api/inventories/{$invId}/validate")->assertOk();

        $this->assertSame(18, $product->stock->fresh()->quantity, 'le stock est ajuste a la quantite comptee');
    }

    public function test_validation_refusee_si_comptage_incomplet(): void
    {
        $owner = $this->makeUser('proprietaire');
        $this->makeProduct(retail: 500, stockQty: 20);

        Sanctum::actingAs($owner);
        $invId = $this->postJson('/api/inventories', ['name' => 'Inv', 'type' => 'complet'])->json('data.id');

        // Aucun comptage effectue
        $this->postJson("/api/inventories/{$invId}/validate")->assertStatus(422);
    }

    public function test_comptage_refuse_sur_inventaire_valide(): void
    {
        $owner = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500, stockQty: 20);

        Sanctum::actingAs($owner);
        $invId = $this->postJson('/api/inventories', ['name' => 'Inv', 'type' => 'complet'])->json('data.id');
        $items = $this->getJson("/api/inventories/{$invId}")->json('data.items');
        $itemId = collect($items)->firstWhere('product_id', $product->id)['id'];
        $this->postJson("/api/inventories/{$invId}/count", [
            'items' => [['inventory_item_id' => $itemId, 'counted_qty' => 20]],
        ])->assertOk();
        $this->postJson("/api/inventories/{$invId}/validate")->assertOk();

        // Nouveau comptage apres validation
        $this->postJson("/api/inventories/{$invId}/count", [
            'items' => [['inventory_item_id' => $itemId, 'counted_qty' => 15]],
        ])->assertStatus(422);
    }

    public function test_inventaire_partiel_limite_a_une_categorie(): void
    {
        $owner = $this->makeUser('proprietaire');
        // Produit dans "Test Cat" (via le trait)
        $this->makeProduct(retail: 500, stockQty: 10);
        // Produit dans une autre categorie, cree en ligne
        $autreCat = Category::create(['name' => 'Autre Cat']);
        $p2 = Product::create([
            'category_id' => $autreCat->id, 'name' => 'Produit Autre', 'unit' => 'piece',
            'min_stock_alert' => 5, 'is_active' => true,
        ]);
        ProductPrice::create(['product_id' => $p2->id, 'purchase_price' => 300, 'retail_price' => 500, 'wholesale_price' => 400, 'wholesale_min_qty' => 12]);
        Stock::create(['product_id' => $p2->id, 'quantity' => 10]);

        Sanctum::actingAs($owner);
        $invId = $this->postJson('/api/inventories', [
            'name' => 'Inv Partiel', 'type' => 'partiel', 'category_id' => $autreCat->id,
        ])->json('data.id');
        // Ajustement : items absent de la reponse de creation -> on passe par show().
        $items = $this->getJson("/api/inventories/{$invId}")->json('data.items');

        $productIds = collect($items)->pluck('product_id');
        $this->assertTrue($productIds->contains($p2->id), 'le produit de la categorie ciblee est present');
        $this->assertCount(1, $productIds, 'seule la categorie ciblee est incluse');
    }
}
