<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class SettingControllerTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_les_6_parametres_sont_exposes_avec_leurs_valeurs_par_defaut(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/settings');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(6, $data);

        $byKey = collect($data)->keyBy('key');
        $this->assertSame(10,    $byKey['remise_max_sans_auth']['value']);
        $this->assertSame('%',   $byKey['remise_max_sans_auth']['unit']);
        $this->assertSame(50000, $byKey['remboursement_max']['value']);
        $this->assertSame(20,    $byKey['sortie_stock_max']['value']);
        $this->assertSame(2000,  $byKey['ecart_caisse_alerte']['value']);
        $this->assertSame(7,     $byKey['peremption_alerte_jours']['value']);
        $this->assertSame(30,    $byKey['inactivite_max_minutes']['value']);
    }

    public function test_la_mise_a_jour_persiste_et_se_reflete_immediatement(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $response = $this->putJson('/api/settings', [
            'remise_max_sans_auth' => 15,
            'ecart_caisse_alerte'  => 3000,
        ]);

        $response->assertOk();
        $this->assertSame(15, (int) Setting::getValue('remise_max_sans_auth'));
        $this->assertSame(3000, (int) Setting::getValue('ecart_caisse_alerte'));

        // Les paramètres non fournis dans le payload restent inchangés
        $this->assertSame(50000, (int) Setting::getValue('remboursement_max', 50000));

        $second = $this->getJson('/api/settings')->json('data');
        $this->assertSame(15, collect($second)->firstWhere('key', 'remise_max_sans_auth')['value']);
    }

    public function test_une_valeur_hors_bornes_est_rejetee(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $response = $this->putJson('/api/settings', [
            'remise_max_sans_auth' => 150, // max autorisé : 100
        ]);

        $response->assertStatus(422);
        $this->assertSame(10, (int) Setting::getValue('remise_max_sans_auth', 10));
    }

    public function test_une_cle_inconnue_est_rejetee(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $this->putJson('/api/settings', [
            'cle_arbitraire' => 42,
        ])->assertStatus(422);
    }

    public function test_necessite_le_role_proprietaire(): void
    {
        $cashier = $this->makeUser('caissier');

        Sanctum::actingAs($cashier);
        $this->getJson('/api/settings')->assertForbidden();
        $this->putJson('/api/settings', ['remise_max_sans_auth' => 15])->assertForbidden();
    }

    public function test_une_seule_entree_d_historique_pour_un_lot_de_changements(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $this->putJson('/api/settings', [
            'remise_max_sans_auth' => 15,
            'ecart_caisse_alerte'  => 3000,
        ])->assertOk();

        $this->assertSame(1, ActivityLog::where('action', 'modification_parametres')->count());
    }
}
