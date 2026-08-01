<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_suppression_utilisateur_sans_transactions_reussit(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $cashier = $this->makeUser('caissier', 'sansventes');

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/users/{$cashier->id}")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $cashier->id]);
    }

    public function test_suppression_bloquee_si_lutilisateur_a_des_ventes(): void
    {
        $owner    = $this->makeUser('proprietaire');
        $cashier  = $this->makeUser('caissier', 'avecventes');
        $product  = $this->makeProduct();
        $this->makeSaleViaApi($cashier, $product);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/users/{$cashier->id}")->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $cashier->id]);
    }

    public function test_impossible_de_supprimer_son_propre_compte(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/users/{$owner->id}")->assertStatus(422);
    }

    public function test_journal_dactivite_renvoie_les_actions_de_lutilisateur_formatees(): void
    {
        // Le journal d'un employe liste ce que LUI a fait (pas ce qu'on lui a fait) :
        // c'est la semantique attendue par le cahier des charges ("historique des actions
        // de chaque employe").
        $owner   = $this->makeUser('proprietaire');
        $cashier = $this->makeUser('caissier', 'jean');
        $product = $this->makeProduct();
        $this->makeSaleViaApi($cashier, $product);

        Sanctum::actingAs($owner);
        $response = $this->getJson("/api/users/{$cashier->id}/logs");

        $response->assertOk();
        $entry = collect($response->json('data'))->firstWhere('action', 'vente');
        $this->assertNotNull($entry);
        $this->assertSame('Sale', $entry['model_type']);
        $this->assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', $entry['created_at']);
    }

    public function test_seul_le_proprietaire_accede_aux_routes_utilisateurs(): void
    {
        $cashier = $this->makeUser('caissier');
        $target  = $this->makeUser('vendeur');

        Sanctum::actingAs($cashier);
        $this->getJson('/api/users')->assertForbidden();
        $this->deleteJson("/api/users/{$target->id}")->assertForbidden();
        $this->getJson("/api/users/{$target->id}/logs")->assertForbidden();
    }
}
