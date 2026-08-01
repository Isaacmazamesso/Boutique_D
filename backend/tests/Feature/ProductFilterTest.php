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

class ProductFilterTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    private function makeNamedProduct(string $name, ?string $barcode, int $stockQty, int $minAlert = 5): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cat Test']);
        $product  = Product::create([
            'category_id'     => $category->id,
            'name'            => $name,
            'unit'            => 'piece',
            'barcode'         => $barcode,
            'min_stock_alert' => $minAlert,
            'is_active'       => true,
        ]);
        ProductPrice::create([
            'product_id' => $product->id, 'purchase_price' => 100,
            'retail_price' => 200, 'wholesale_price' => 150, 'wholesale_min_qty' => 12,
        ]);
        Stock::create(['product_id' => $product->id, 'quantity' => $stockQty]);

        return $product;
    }

    public function test_recherche_par_code_barres(): void
    {
        $owner = $this->makeUser('proprietaire');
        $this->makeNamedProduct('Riz local', '6191234567890', 50);
        $this->makeNamedProduct('Huile de table', '6199999999999', 50);

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/products?search=6191234567890');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Riz local'));
        $this->assertFalse($names->contains('Huile de table'));
    }

    public function test_filtre_statut_actif_inactif(): void
    {
        $owner  = $this->makeUser('proprietaire');
        $actif  = $this->makeNamedProduct('Produit actif', null, 10);
        $inactif = $this->makeNamedProduct('Produit inactif', null, 10);
        $inactif->update(['is_active' => false]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/products?status=actif');
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Produit actif'));
        $this->assertFalse($names->contains('Produit inactif'));

        $response = $this->getJson('/api/products?status=inactif');
        $names = collect($response->json('data'))->pluck('name');
        $this->assertFalse($names->contains('Produit actif'));
        $this->assertTrue($names->contains('Produit inactif'));
    }

    public function test_filtre_par_niveau_de_stock(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $rupture = $this->makeNamedProduct('En rupture', null, 0, 5);
        $bas     = $this->makeNamedProduct('Stock bas', null, 3, 5);
        $normal  = $this->makeNamedProduct('Stock normal', null, 50, 5);

        Sanctum::actingAs($owner);

        $r = collect($this->getJson('/api/products?stock_status=rupture')->json('data'))->pluck('name');
        $this->assertEqualsCanonicalizing(['En rupture'], $r->all());

        $b = collect($this->getJson('/api/products?stock_status=bas')->json('data'))->pluck('name');
        $this->assertEqualsCanonicalizing(['Stock bas'], $b->all());

        $n = collect($this->getJson('/api/products?stock_status=normal')->json('data'))->pluck('name');
        $this->assertEqualsCanonicalizing(['Stock normal'], $n->all());
    }
}
