<?php

namespace Tests\Feature;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class SaleSyncDedupTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    private function saleBody(int $productId, string $uuid): array
    {
        return [
            'uuid'           => $uuid,
            'sale_type'      => 'detail',
            'payment_method' => 'especes',
            'amount_paid'    => 1000,
            'items'          => [['product_id' => $productId, 'quantity' => 1]],
        ];
    }

    public function test_une_vente_avec_uuid_neuf_est_creee_et_persiste_le_sync_uuid(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 10);
        $uuid    = '11111111-1111-4111-8111-111111111111';

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', $this->saleBody($product->id, $uuid));

        $response->assertCreated();
        $this->assertSame(1, Sale::count());
        $this->assertSame($uuid, Sale::first()->sync_uuid);
    }

    public function test_rejeu_du_meme_uuid_ne_cree_pas_de_doublon(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 10);
        $uuid    = '22222222-2222-4222-8222-222222222222';

        Sanctum::actingAs($cashier);
        $first  = $this->postJson('/api/sales', $this->saleBody($product->id, $uuid));
        $second = $this->postJson('/api/sales', $this->saleBody($product->id, $uuid));

        $first->assertCreated();
        $second->assertOk(); // 200, pas 201
        $this->assertSame(1, Sale::count(), 'un rejeu ne doit jamais creer une seconde vente');
        // Le rejeu renvoie la vente existante (meme receipt_number)
        $this->assertSame(
            $first->json('data.receipt_number'),
            $second->json('data.receipt_number')
        );
        // Le stock n'a ete decremente qu'une seule fois
        $this->assertSame(9, $product->stock->fresh()->quantity);
    }

    public function test_une_vente_sans_uuid_reste_inchangee(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 10);

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', [
            'sale_type'      => 'detail',
            'payment_method' => 'especes',
            'amount_paid'    => 1000,
            'items'          => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertCreated();
        $this->assertNull(Sale::first()->sync_uuid);
    }
}
