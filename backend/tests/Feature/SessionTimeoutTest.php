<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class SessionTimeoutTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_token_actif_recemment_est_accepte(): void
    {
        $user  = $this->makeUser('caissier', 'jean');
        $token = $user->createToken('test')->plainTextToken;
        $user->tokens()->first()->forceFill(['last_used_at' => now()->subMinutes(5)])->save();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_token_inactif_au_dela_du_seuil_est_rejete_et_revoque(): void
    {
        $user  = $this->makeUser('caissier', 'jean');
        $token = $user->createToken('test')->plainTextToken;
        $accessToken = $user->tokens()->first();
        $accessToken->forceFill(['last_used_at' => now()->subMinutes(45)])->save();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Session expirée par inactivité. Veuillez vous reconnecter.');

        $this->assertNull($accessToken->fresh(), 'le token doit avoir ete supprime');
    }

    public function test_seuil_configurable_via_settings(): void
    {
        Setting::setValue('inactivite_max_minutes', '10');

        $user  = $this->makeUser('caissier', 'jean');
        $token = $user->createToken('test')->plainTextToken;
        $user->tokens()->first()->forceFill(['last_used_at' => now()->subMinutes(15)])->save();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_token_jamais_utilise_nest_pas_rejete(): void
    {
        $user  = $this->makeUser('caissier', 'jean');
        $token = $user->createToken('test')->plainTextToken;
        // last_used_at reste null juste apres creation — ne doit pas etre traite comme expire.

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_login_renvoie_le_seuil_de_session_configure(): void
    {
        Setting::setValue('inactivite_max_minutes', '45');
        $this->makeUser('caissier', 'jean')->update(['password' => 'secret123']);

        $response = $this->postJson('/api/auth/login', ['username' => 'jean', 'password' => 'secret123']);

        $response->assertOk();
        $this->assertSame(45, $response->json('data.session_timeout_minutes'));
    }
}
