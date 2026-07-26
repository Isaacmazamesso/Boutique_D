<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class CustomerCrudTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_creation_d_un_client(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');

        Sanctum::actingAs($gestionnaire);
        $response = $this->postJson('/api/customers', [
            'name' => 'Awa Diop', 'phone' => '770000001', 'note' => 'Voisine',
        ]);

        $response->assertCreated();
        $this->assertSame('Awa Diop', $response->json('data.name'));
        $this->assertDatabaseHas('customers', ['phone' => '770000001']);
    }

    public function test_telephone_en_doublon_refuse(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        Customer::create(['name' => 'Existant', 'phone' => '770000002']);

        Sanctum::actingAs($gestionnaire);
        $this->postJson('/api/customers', ['name' => 'Autre', 'phone' => '770000002'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ce numéro de téléphone est déjà enregistré pour un autre client.');
    }

    public function test_un_caissier_peut_creer_mais_pas_lister(): void
    {
        $caissier = $this->makeUser('caissier');

        Sanctum::actingAs($caissier);
        $this->postJson('/api/customers', ['name' => 'Client Caisse', 'phone' => '770000003'])
            ->assertCreated();
        $this->getJson('/api/customers')->assertForbidden();
    }

    public function test_recherche_par_nom_ou_telephone(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        Customer::create(['name' => 'Fatou Sow', 'phone' => '771111111']);
        Customer::create(['name' => 'Moussa Ba', 'phone' => '772222222']);

        Sanctum::actingAs($gestionnaire);
        $parNom = $this->getJson('/api/customers?search=Fatou')->json('data');
        $this->assertCount(1, $parNom);
        $this->assertSame('Fatou Sow', $parNom[0]['name']);

        $parTel = $this->getJson('/api/customers?search=772222')->json('data');
        $this->assertCount(1, $parTel);
        $this->assertSame('Moussa Ba', $parTel[0]['name']);
    }

    public function test_modification_d_un_client(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $customer = Customer::create(['name' => 'Ancien Nom', 'phone' => '773333333']);

        Sanctum::actingAs($gestionnaire);
        $this->putJson("/api/customers/{$customer->id}", ['name' => 'Nouveau Nom'])
            ->assertOk();

        $this->assertSame('Nouveau Nom', $customer->fresh()->name);
    }

    public function test_suppression_d_un_client_sans_vente(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $customer = Customer::create(['name' => 'A Supprimer', 'phone' => '774444444']);

        Sanctum::actingAs($gestionnaire);
        $this->deleteJson("/api/customers/{$customer->id}")->assertOk();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_caissier_ne_peut_pas_supprimer(): void
    {
        $caissier = $this->makeUser('caissier');
        $customer = Customer::create(['name' => 'Client', 'phone' => '775555555']);

        Sanctum::actingAs($caissier);
        $this->deleteJson("/api/customers/{$customer->id}")->assertForbidden();
    }
}
