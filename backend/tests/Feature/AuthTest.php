<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    private function makeCashierWithPassword(string $password = 'secret123'): User
    {
        $user = $this->makeUser('caissier', 'jean');
        // Le modele User caste 'password' => 'hashed' : on assigne le mot de passe EN CLAIR,
        // le cast le hache automatiquement (ne jamais passer Hash::make ici — le cast le detecterait
        // deja hache mais l'assignation en clair est la forme sans ambiguite).
        $user->update(['password' => $password]);
        return $user;
    }

    public function test_login_reussi_renvoie_un_token(): void
    {
        $this->makeCashierWithPassword('secret123');

        $response = $this->postJson('/api/auth/login', ['username' => 'jean', 'password' => 'secret123']);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame('caissier', $response->json('data.user.role'));
    }

    public function test_mauvais_mot_de_passe_incremente_les_tentatives(): void
    {
        $user = $this->makeCashierWithPassword();

        $this->postJson('/api/auth/login', ['username' => 'jean', 'password' => 'faux'])
            ->assertStatus(401);

        $this->assertSame(1, $user->fresh()->failed_attempts);
    }

    public function test_verrouillage_apres_cinq_echecs(): void
    {
        $user = $this->makeCashierWithPassword();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', ['username' => 'jean', 'password' => 'faux'])->assertStatus(401);
        }
        // 5e tentative : verrouillage
        $this->postJson('/api/auth/login', ['username' => 'jean', 'password' => 'faux'])
            ->assertStatus(429);

        $this->assertNotNull($user->fresh()->locked_until);
    }

    public function test_compte_desactive_refuse(): void
    {
        $user = $this->makeCashierWithPassword();
        $user->update(['is_active' => false]);

        $this->postJson('/api/auth/login', ['username' => 'jean', 'password' => 'secret123'])
            ->assertStatus(403);
    }

    public function test_compte_verrouille_refuse(): void
    {
        $user = $this->makeCashierWithPassword();
        $user->update(['locked_until' => now()->addMinutes(10)]);

        $this->postJson('/api/auth/login', ['username' => 'jean', 'password' => 'secret123'])
            ->assertStatus(429);
    }

    public function test_utilisateur_inexistant_refuse(): void
    {
        $this->seedRoles();

        $this->postJson('/api/auth/login', ['username' => 'inconnu', 'password' => 'x'])
            ->assertStatus(401);
    }

    public function test_login_reussi_reinitialise_les_tentatives(): void
    {
        $user = $this->makeCashierWithPassword('secret123');
        $user->update(['failed_attempts' => 3]);

        $this->postJson('/api/auth/login', ['username' => 'jean', 'password' => 'secret123'])->assertOk();

        $this->assertSame(0, $user->fresh()->failed_attempts);
    }

    public function test_changement_de_mot_de_passe_reussi(): void
    {
        $user = $this->makeCashierWithPassword('ancien123');

        Sanctum::actingAs($user);
        $this->putJson('/api/auth/password', [
            'current_password'          => 'ancien123',
            'new_password'              => 'nouveau123',
            'new_password_confirmation' => 'nouveau123',
        ])->assertOk();

        $this->assertTrue(Hash::check('nouveau123', $user->fresh()->password));
    }

    public function test_mauvais_mot_de_passe_actuel_refuse(): void
    {
        $user = $this->makeCashierWithPassword('ancien123');

        Sanctum::actingAs($user);
        $this->putJson('/api/auth/password', [
            'current_password'          => 'faux',
            'new_password'              => 'nouveau123',
            'new_password_confirmation' => 'nouveau123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('ancien123', $user->fresh()->password), 'le mot de passe ne doit pas changer');
    }

    public function test_nouveau_mot_de_passe_trop_court_refuse(): void
    {
        $user = $this->makeCashierWithPassword('ancien123');

        Sanctum::actingAs($user);
        $this->putJson('/api/auth/password', [
            'current_password'          => 'ancien123',
            'new_password'              => '123',
            'new_password_confirmation' => '123',
        ])->assertStatus(422);
    }

    public function test_confirmation_manquante_refuse(): void
    {
        $user = $this->makeCashierWithPassword('ancien123');

        Sanctum::actingAs($user);
        $this->putJson('/api/auth/password', [
            'current_password' => 'ancien123',
            'new_password'     => 'nouveau123',
            // pas de new_password_confirmation
        ])->assertStatus(422);
    }
}
