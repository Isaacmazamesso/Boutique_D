<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class CashSessionTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_ouverture_de_session(): void
    {
        $cashier = $this->makeUser('caissier');

        Sanctum::actingAs($cashier);
        $this->postJson('/api/cash-sessions/open', ['opening_amount' => 50000])
            ->assertCreated();
    }

    public function test_double_ouverture_refusee(): void
    {
        $cashier = $this->makeUser('caissier');

        Sanctum::actingAs($cashier);
        $this->postJson('/api/cash-sessions/open', ['opening_amount' => 50000])->assertCreated();
        $this->postJson('/api/cash-sessions/open', ['opening_amount' => 30000])->assertStatus(422);
    }

    public function test_cloture_sans_session_refusee(): void
    {
        $cashier = $this->makeUser('caissier');

        Sanctum::actingAs($cashier);
        $this->postJson('/api/cash-sessions/close', ['closing_amount' => 50000])
            ->assertStatus(404);
    }

    public function test_cloture_calcule_l_ecart(): void
    {
        // theoretical = opening_amount + ventes especes - remboursements
        $cashier = $this->makeUser('caissier');
        Sanctum::actingAs($cashier);
        $this->postJson('/api/cash-sessions/open', ['opening_amount' => 10000])->assertCreated();

        // Une vente especes de 1000 (1 x 500 x 2) via l'API, rattachee a la session ouverte
        $product = $this->makeProduct(retail: 500, stockQty: 100);
        $this->makeSaleViaApi($cashier, $product, qty: 2); // total 1000, especes

        // Theorique attendu = 10000 + 1000 = 11000. On clot avec 11000 → ecart 0.
        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/cash-sessions/close', ['closing_amount' => 11000]);

        $response->assertOk();
        $this->assertSame(11000, $response->json('data.theoretical_amount'));
        $this->assertSame(0, $response->json('data.difference'));
    }

    public function test_ecart_declenche_une_alerte(): void
    {
        Setting::setValue('ecart_caisse_alerte', 2000);
        $cashier = $this->makeUser('caissier');
        Sanctum::actingAs($cashier);
        $this->postJson('/api/cash-sessions/open', ['opening_amount' => 10000])->assertCreated();

        // Clot avec un montant tres eloigne du theorique (10000) → ecart 5000 > seuil 2000
        $response = $this->postJson('/api/cash-sessions/close', ['closing_amount' => 15000]);

        $response->assertOk();
        $this->assertSame(5000, $response->json('data.difference'));
        $this->assertTrue($response->json('data.alerte_ecart'));
    }

    public function test_current_renvoie_la_session_ouverte(): void
    {
        $cashier = $this->makeUser('caissier');
        Sanctum::actingAs($cashier);
        $this->postJson('/api/cash-sessions/open', ['opening_amount' => 50000])->assertCreated();

        $response = $this->getJson('/api/cash-sessions/current');
        $response->assertOk();
        $this->assertSame(50000, $response->json('data.opening_amount'));
    }
}
