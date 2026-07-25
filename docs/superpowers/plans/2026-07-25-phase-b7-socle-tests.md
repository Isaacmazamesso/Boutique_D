# Phase B7 — Socle de tests des modules critiques — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter un socle de tests automatisés (characterization tests) pour les 5 modules critiques historiques sans couverture — auth, cœur POS, mouvements de stock, workflow d'inventaire, sessions de caisse — afin de figer leur comportement actuel avant la v1.

**Architecture:** 5 nouveaux fichiers `tests/Feature/*.php`, un par module, tous bâtis sur le trait existant `tests/Support/CreatesShopData.php` (réutilisé tel quel, non modifié). Aucun code de production n'est modifié : ces tests affirment le comportement existant. Si un test révèle un comportement inattendu, il est **documenté dans le rapport de tâche** et le test est ajusté pour refléter le comportement RÉEL (jamais le code de production).

**Tech Stack:** Laravel 12, PHPUnit (`php artisan test`), SQLite `:memory:` (déjà configuré), Laravel Sanctum (`Sanctum::actingAs`), trait `CreatesShopData`.

## Global Constraints

- Spec `docs/superpowers/specs/2026-07-25-phase-b7-socle-tests-design.md`. Dernier module de la conformité fonctionnelle.
- **Aucune modification du code de production.** Seuls des fichiers de test sont créés. Le trait `CreatesShopData` n'est PAS modifié (les fixtures manquantes sont créées en ligne dans le test qui en a besoin).
- Ces tests **caractérisent le comportement existant** : si une assertion prédite dans ce plan ne correspond pas au comportement réel (statut HTTP, forme de réponse, message), ajuster le TEST pour refléter le réel et **le noter dans le rapport** — ne jamais modifier le contrôleur.
- Enveloppe de réponse : `{success, message, data}`. Les tests lisent `->json('data')` / `->json('message')`.
- Portées de rôle à respecter (routes) : `POST /sales` (tout authentifié) ; `POST /stock/entries|exits` et `POST /inventories` (`proprietaire|gestionnaire`) ; `POST /inventories/{id}/validate` (`proprietaire`) ; `POST /inventories/{id}/count` (tout authentifié). Un caissier ne peut donc PAS atteindre `storeExit` → le seuil `sortie_stock_max` (contrôleur, `!hasRole('proprietaire')`) se teste avec un **gestionnaire**. Le seuil de remise (`SaleController`, `!hasRole('proprietaire')`) se teste avec un **caissier** (pas de gate de route sur `/sales`).
- Branche de travail : `feat/b7-socle-tests`, créée depuis `master`. Un commit par tâche. Ne pas pousser vers origin sans demande du client.
- À la fin de chaque tâche, `cd backend && php artisan test` passe en entier (51 existants + les nouveaux). Sortie propre, aucun warning introduit.
- Rôles disponibles (guard `web`) : `proprietaire`, `gestionnaire`, `caissier`, `vendeur`. Helpers du trait : `makeUser(role, ?username)`, `makeProduct(retail:500, purchase:300, stockQty:100)` (crée aussi ProductPrice `wholesale_price = retail-100`, `wholesale_min_qty=12`, et une catégorie `Test Cat`, `min_stock_alert=5`), `openSession(User)`, `makeSaleViaApi(cashier, product, qty)` (ouvre une session si besoin, `actingAs`, POST /sales, retourne `data`).

## File Structure

- **Create:** `backend/tests/Feature/AuthTest.php`
- **Create:** `backend/tests/Feature/SaleStoreTest.php`
- **Create:** `backend/tests/Feature/StockMovementTest.php`
- **Create:** `backend/tests/Feature/InventoryWorkflowTest.php`
- **Create:** `backend/tests/Feature/CashSessionTest.php`
- **No changes:** aucun fichier de production, ni `tests/Support/CreatesShopData.php`.

---

### Task 1: `AuthTest` — authentification et changement de mot de passe

**Files:**
- Create: `backend/tests/Feature/AuthTest.php`

**Interfaces:**
- Consumes: `CreatesShopData::makeUser`, `App\Models\User`, `Illuminate\Support\Facades\Hash`, `Laravel\Sanctum\Sanctum`.
- Produces: rien (fichier de test terminal).

- [ ] **Step 1: Écrire le fichier de test**

`backend/tests/Feature/AuthTest.php` :
```php
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
```

- [ ] **Step 2: Lancer et faire passer**

Run: `cd backend && php artisan test --filter=AuthTest`
Attendu : 11 PASS. Si une assertion échoue parce que le comportement réel diffère (ex. statut HTTP différent, ou `makeUser` crée le mot de passe autrement), ajuster l'assertion du test pour refléter le comportement RÉEL du contrôleur (lire `AuthController::login`/`changePassword`) et le noter dans le rapport — ne jamais modifier le contrôleur.

- [ ] **Step 3: Suite complète**

Run: `php artisan test` — Attendu : tous PASS (62 tests).

- [ ] **Step 4: Commit**

```bash
git add backend/tests/Feature/AuthTest.php
git commit -m "test: socle de tests authentification (login, verrouillage, changement mot de passe)"
```

---

### Task 2: `SaleStoreTest` — cœur du POS (`SaleController::store`)

**Files:**
- Create: `backend/tests/Feature/SaleStoreTest.php`

**Interfaces:**
- Consumes: `CreatesShopData` (`makeUser`, `makeProduct`, `openSession`), `App\Models\Setting`, `Sanctum`.
- Produces: rien.

- [ ] **Step 1: Écrire le fichier de test**

`backend/tests/Feature/SaleStoreTest.php` :
```php
<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class SaleStoreTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_stock_insuffisant_refuse(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 3);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 5000,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ])->assertStatus(422);

        $this->assertSame(3, $product->stock->fresh()->quantity, 'le stock ne doit pas bouger sur un refus');
    }

    public function test_quantite_gros_sous_le_minimum_refuse(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100); // wholesale_min_qty = 12

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'gros', 'payment_method' => 'especes', 'amount_paid' => 5000,
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertStatus(422);
    }

    public function test_prix_gros_applique_au_dela_du_minimum(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100); // wholesale_price = 400, min 12

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', [
            'sale_type' => 'gros', 'payment_method' => 'especes', 'amount_paid' => 100000,
            'items' => [['product_id' => $product->id, 'quantity' => 12]],
        ]);

        $response->assertCreated();
        $this->assertSame(4800, $response->json('data.total'), '12 x prix gros 400 = 4800');
    }

    public function test_remise_excessive_refusee_pour_caissier(): void
    {
        Setting::setValue('remise_max_sans_auth', 10);
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 1000, stockQty: 100);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 100000,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'discount_type' => 'percent', 'discount_value' => 50,
        ])->assertStatus(403);
    }

    public function test_remise_excessive_autorisee_pour_proprietaire(): void
    {
        Setting::setValue('remise_max_sans_auth', 10);
        $owner = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 1000, stockQty: 100);

        Sanctum::actingAs($owner);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 100000,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'discount_type' => 'percent', 'discount_value' => 50,
        ])->assertCreated();
    }

    public function test_montant_especes_insuffisant_refuse(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 100,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_caissier_sans_session_refuse(): void
    {
        $cashier = $this->makeUser('caissier'); // pas de session ouverte
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 500,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_monnaie_rendue_calculee(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 1000,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertCreated();
        $this->assertSame(500, $response->json('data.total'));
        $this->assertSame(500, $response->json('data.change_given'), '1000 recu - 500 du = 500 rendu');
    }
}
```

- [ ] **Step 2: Lancer et faire passer**

Run: `php artisan test --filter=SaleStoreTest`
Attendu : 8 PASS. Ajuster tout écart au comportement réel (lire `SaleController::store`) en notant dans le rapport.

- [ ] **Step 3: Suite complète**

Run: `php artisan test` — Attendu : tous PASS (70 tests).

- [ ] **Step 4: Commit**

```bash
git add backend/tests/Feature/SaleStoreTest.php
git commit -m "test: socle de tests coeur POS (stock, prix gros, seuil remise, montant, session)"
```

---

### Task 3: `StockMovementTest` — entrées / sorties / alertes

**Files:**
- Create: `backend/tests/Feature/StockMovementTest.php`

**Interfaces:**
- Consumes: `CreatesShopData` (`makeUser`, `makeProduct`), `App\Models\Setting`, `Sanctum`.
- Produces: rien.

- [ ] **Step 1: Écrire le fichier de test**

`backend/tests/Feature/StockMovementTest.php` :
```php
<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_entree_incremente_le_stock(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, purchase: 300, stockQty: 10);

        Sanctum::actingAs($gestionnaire);
        $response = $this->postJson('/api/stock/entries', [
            'product_id' => $product->id, 'quantity' => 20, 'purchase_price' => 300,
        ]);

        $response->assertCreated();
        $this->assertSame(30, $product->stock->fresh()->quantity);
    }

    public function test_entree_met_a_jour_le_prix_achat(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, purchase: 300, stockQty: 10);

        Sanctum::actingAs($gestionnaire);
        $this->postJson('/api/stock/entries', [
            'product_id' => $product->id, 'quantity' => 5, 'purchase_price' => 350,
        ])->assertCreated();

        $this->assertSame(350, $product->price->fresh()->purchase_price);
    }

    public function test_sortie_decremente_le_stock(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, stockQty: 30);

        Sanctum::actingAs($gestionnaire);
        $this->postJson('/api/stock/exits', [
            'product_id' => $product->id, 'quantity' => 5, 'reason' => 'casse',
        ])->assertCreated();

        $this->assertSame(25, $product->stock->fresh()->quantity);
    }

    public function test_sortie_stock_insuffisant_refuse(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, stockQty: 3);

        Sanctum::actingAs($gestionnaire);
        $this->postJson('/api/stock/exits', [
            'product_id' => $product->id, 'quantity' => 10, 'reason' => 'casse',
        ])->assertStatus(422);

        $this->assertSame(3, $product->stock->fresh()->quantity);
    }

    public function test_sortie_au_dela_du_seuil_refusee_pour_gestionnaire(): void
    {
        Setting::setValue('sortie_stock_max', 20);
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($gestionnaire);
        $this->postJson('/api/stock/exits', [
            'product_id' => $product->id, 'quantity' => 25, 'reason' => 'perte',
        ])->assertStatus(403);
    }

    public function test_sortie_au_dela_du_seuil_autorisee_pour_proprietaire(): void
    {
        Setting::setValue('sortie_stock_max', 20);
        $owner = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($owner);
        $this->postJson('/api/stock/exits', [
            'product_id' => $product->id, 'quantity' => 25, 'reason' => 'perte',
        ])->assertCreated();

        $this->assertSame(75, $product->stock->fresh()->quantity);
    }

    public function test_motif_de_sortie_invalide_refuse(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($gestionnaire);
        $this->postJson('/api/stock/exits', [
            'product_id' => $product->id, 'quantity' => 5, 'reason' => 'motif_bidon',
        ])->assertStatus(422);
    }

    public function test_alertes_listent_stock_bas_et_rupture(): void
    {
        $gestionnaire = $this->makeUser('gestionnaire');
        $this->makeProduct(retail: 500, stockQty: 3);  // min_stock_alert=5 → stock_bas
        $this->makeProduct(retail: 500, stockQty: 0);  // rupture

        Sanctum::actingAs($gestionnaire);
        $response = $this->getJson('/api/stock/alerts');

        $response->assertOk();
        $types = collect($response->json('data.alerts'))->pluck('type');
        $this->assertTrue($types->contains('stock_bas'));
        $this->assertTrue($types->contains('rupture'));
    }
}
```

- [ ] **Step 2: Lancer et faire passer**

Run: `php artisan test --filter=StockMovementTest`
Attendu : 8 PASS. Ajuster tout écart au comportement réel (lire `StockController`) en notant dans le rapport.

- [ ] **Step 3: Suite complète**

Run: `php artisan test` — Attendu : tous PASS (78 tests).

- [ ] **Step 4: Commit**

```bash
git add backend/tests/Feature/StockMovementTest.php
git commit -m "test: socle de tests mouvements de stock (entrees, sorties, seuils, alertes)"
```

---

### Task 4: `InventoryWorkflowTest` — workflow d'inventaire complet

**Files:**
- Create: `backend/tests/Feature/InventoryWorkflowTest.php`

**Interfaces:**
- Consumes: `CreatesShopData` (`makeUser`, `makeProduct`), `App\Models\Category`, `App\Models\Product`, `App\Models\Stock`, `App\Models\ProductPrice`, `Sanctum`.
- Produces: rien.

- [ ] **Step 1: Écrire le fichier de test**

`backend/tests/Feature/InventoryWorkflowTest.php` :
```php
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
        $items = $response->json('data.items');
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
        $inv = $this->postJson('/api/inventories', ['name' => 'Inv', 'type' => 'complet'])->json('data');
        $itemId = collect($inv['items'])->firstWhere('product_id', $product->id)['id'];

        $this->postJson("/api/inventories/{$inv['id']}/count", [
            'items' => [['inventory_item_id' => $itemId, 'counted_qty' => 18]],
        ])->assertOk();

        $detail = $this->getJson("/api/inventories/{$inv['id']}")->json('data');
        $line = collect($detail['items'])->firstWhere('id', $itemId);
        $this->assertSame(18, $line['counted_qty']);
        $this->assertSame(-2, $line['difference'], '18 comptes - 20 theoriques = -2');
    }

    public function test_validation_ajuste_le_stock(): void
    {
        $owner = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500, stockQty: 20);

        Sanctum::actingAs($owner);
        $inv = $this->postJson('/api/inventories', ['name' => 'Inv', 'type' => 'complet'])->json('data');
        $itemId = collect($inv['items'])->firstWhere('product_id', $product->id)['id'];

        $this->postJson("/api/inventories/{$inv['id']}/count", [
            'items' => [['inventory_item_id' => $itemId, 'counted_qty' => 18]],
        ])->assertOk();

        $this->postJson("/api/inventories/{$inv['id']}/validate")->assertOk();

        $this->assertSame(18, $product->stock->fresh()->quantity, 'le stock est ajuste a la quantite comptee');
    }

    public function test_validation_refusee_si_comptage_incomplet(): void
    {
        $owner = $this->makeUser('proprietaire');
        $this->makeProduct(retail: 500, stockQty: 20);

        Sanctum::actingAs($owner);
        $inv = $this->postJson('/api/inventories', ['name' => 'Inv', 'type' => 'complet'])->json('data');

        // Aucun comptage effectue
        $this->postJson("/api/inventories/{$inv['id']}/validate")->assertStatus(422);
    }

    public function test_comptage_refuse_sur_inventaire_valide(): void
    {
        $owner = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500, stockQty: 20);

        Sanctum::actingAs($owner);
        $inv = $this->postJson('/api/inventories', ['name' => 'Inv', 'type' => 'complet'])->json('data');
        $itemId = collect($inv['items'])->firstWhere('product_id', $product->id)['id'];
        $this->postJson("/api/inventories/{$inv['id']}/count", [
            'items' => [['inventory_item_id' => $itemId, 'counted_qty' => 20]],
        ])->assertOk();
        $this->postJson("/api/inventories/{$inv['id']}/validate")->assertOk();

        // Nouveau comptage apres validation
        $this->postJson("/api/inventories/{$inv['id']}/count", [
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
        $inv = $this->postJson('/api/inventories', [
            'name' => 'Inv Partiel', 'type' => 'partiel', 'category_id' => $autreCat->id,
        ])->json('data');

        $productIds = collect($inv['items'])->pluck('product_id');
        $this->assertTrue($productIds->contains($p2->id), 'le produit de la categorie ciblee est present');
        $this->assertCount(1, $productIds, 'seule la categorie ciblee est incluse');
    }
}
```

- [ ] **Step 2: Lancer et faire passer**

Run: `php artisan test --filter=InventoryWorkflowTest`
Attendu : 7 PASS. La forme exacte de `data.items` (clés `id`, `product_id`, `theoretical_qty`, `counted_qty`, `difference`) provient de `InventoryController::formatInventory` en mode détaillé — si une clé diffère, lire ce formatter et ajuster le test, en notant dans le rapport.

- [ ] **Step 3: Suite complète**

Run: `php artisan test` — Attendu : tous PASS (85 tests).

- [ ] **Step 4: Commit**

```bash
git add backend/tests/Feature/InventoryWorkflowTest.php
git commit -m "test: socle de tests workflow inventaire (creation, comptage, validation, ajustement stock)"
```

---

### Task 5: `CashSessionTest` — ouverture / clôture / écart

**Files:**
- Create: `backend/tests/Feature/CashSessionTest.php`

**Interfaces:**
- Consumes: `CreatesShopData` (`makeUser`, `makeProduct`, `makeSaleViaApi`), `Sanctum`.
- Produces: rien.

- [ ] **Step 1: Écrire le fichier de test**

`backend/tests/Feature/CashSessionTest.php` :
```php
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
```

- [ ] **Step 2: Lancer et faire passer**

Run: `php artisan test --filter=CashSessionTest`
Attendu : 6 PASS. Le calcul de `theoreticalAmount()` (`opening_amount + totalSales() - totalRefunds()`) et la forme de la réponse de `close` (`data.theoretical_amount`, `data.difference`, `data.alerte_ecart`) proviennent de `CashSessionController::close` — si un montant ou une clé diffère (ex. `totalSales()` ne compte que les ventes espèces, ou toutes), lire le modèle `CashSession` et le contrôleur, ajuster le test et le noter dans le rapport.

- [ ] **Step 3: Suite complète**

Run: `php artisan test` — Attendu : tous PASS (91 tests).

- [ ] **Step 4: Commit**

```bash
git add backend/tests/Feature/CashSessionTest.php
git commit -m "test: socle de tests sessions de caisse (ouverture, cloture, ecart)"
```

---

### Task 6: Vérification finale du module

**Files:** aucun changement de code — vérification uniquement.

- [ ] **Step 1: Suite complète, sortie propre**

Run: `cd backend && php artisan test` — Attendu : tous PASS (~91 tests), aucune erreur ni warning.

- [ ] **Step 2: Recenser les comportements suspects**

Rassembler, depuis les rapports des Tasks 1–5, tout comportement inattendu rencontré (assertion ajustée pour coller au réel). Les consigner dans le rapport de cette tâche comme liste unique « comportements à examiner » pour décision ultérieure du client. S'il n'y en a aucun, l'indiquer explicitement.

- [ ] **Step 3: Non-régression**

Confirmer via `git diff master --stat` que la branche n'a ajouté QUE des fichiers `tests/Feature/*.php` (aucun fichier de production, ni `tests/Support/CreatesShopData.php`, ni migration, ni route).

- [ ] **Step 4: Commit (si ajustement)**

Sinon, rien à commit — cette tâche clôt le module avant la revue finale de branche.
