<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class ProductPriceRuleTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    private function baseProductPayload(array $overrides = []): array
    {
        return array_merge([
            'name'            => 'Riz 5kg',
            'category_id'     => \App\Models\Category::create(['name' => 'Cereales'])->id,
            'unit'            => 'sac',
            'purchase_price'  => 3000,
            'retail_price'    => 3500,
            'wholesale_price' => 3200,
        ], $overrides);
    }

    public function test_creation_refusee_si_prix_detail_sous_le_cout(): void
    {
        $owner = $this->makeUser('proprietaire');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/products', $this->baseProductPayload(['retail_price' => 2500]));

        $response->assertStatus(422);
        $this->assertTrue($response->json('data.price_below_cost'));
        $this->assertDatabaseMissing('products', ['name' => 'Riz 5kg']);
    }

    public function test_creation_acceptee_avec_confirmation_explicite(): void
    {
        $owner = $this->makeUser('proprietaire');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/products', $this->baseProductPayload([
            'retail_price'       => 2500,
            'confirm_below_cost' => true,
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('products', ['name' => 'Riz 5kg']);
    }

    public function test_creation_normale_non_affectee(): void
    {
        $owner = $this->makeUser('proprietaire');
        Sanctum::actingAs($owner);

        $this->postJson('/api/products', $this->baseProductPayload())->assertCreated();
    }

    public function test_modification_refusee_si_nouveau_prix_gros_sous_le_cout(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 3500, purchase: 3000);

        Sanctum::actingAs($owner);
        $response = $this->putJson("/api/products/{$product->id}", ['wholesale_price' => 2000]);

        $response->assertStatus(422);
        $this->assertTrue($response->json('data.price_below_cost'));
        $this->assertNotEquals(2000, $product->fresh()->price->wholesale_price, 'le prix gros ne doit pas avoir ete modifie');
    }

    public function test_modification_refusee_si_hausse_du_prix_dachat_passe_sous_le_prix_existant(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 3500, purchase: 3000);

        Sanctum::actingAs($owner);
        // On ne touche pas au prix de vente, mais on monte le prix d'achat au-dessus du prix de vente existant.
        $response = $this->putJson("/api/products/{$product->id}", ['purchase_price' => 4000]);

        $response->assertStatus(422);
    }

    public function test_modification_acceptee_avec_confirmation(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 3500, purchase: 3000);

        Sanctum::actingAs($owner);
        $response = $this->putJson("/api/products/{$product->id}", [
            'wholesale_price'    => 2000,
            'confirm_below_cost' => true,
        ]);

        $response->assertOk();
        $this->assertSame(2000, $product->fresh()->price->wholesale_price);
    }

    public function test_historique_des_prix_est_consultable_et_formate(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 3500, purchase: 3000);

        Sanctum::actingAs($owner);
        $this->putJson("/api/products/{$product->id}", [
            'retail_price' => 3800,
            'price_reason' => 'Hausse fournisseur',
        ])->assertOk();

        $response = $this->getJson("/api/products/{$product->id}/price-history");

        $response->assertOk();
        $entry = $response->json('data.0');
        $this->assertSame(3500, $entry['old_retail_price']);
        $this->assertSame(3800, $entry['new_retail_price']);
        $this->assertSame('Hausse fournisseur', $entry['reason']);
        $this->assertSame('Proprietaire Test', $entry['changed_by']);
        $this->assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', $entry['created_at']);
    }
}
