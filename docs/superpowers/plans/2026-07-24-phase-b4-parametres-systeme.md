# Phase B4 — Paramètres système éditables — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rendre éditables par le propriétaire les 5 seuils métier actuellement codés en dur dans le backend (valeurs par défaut passées à `Setting::getValue()`), via une nouvelle page « Paramètres ».

**Architecture:** Backend : un nouveau `SettingController` avec un schéma statique des 5 paramètres connus (clé, libellé, description, unité, valeur par défaut, bornes min/max) — `GET /settings` renvoie l'état actuel de chacun, `PUT /settings` valide et écrit un sous-ensemble d'entre eux via le modèle `Setting` existant (déjà utilisé en lecture par 5 contrôleurs différents, jamais en écriture depuis une UI). Frontend : nouvelle page `settings.html` qui affiche un formulaire généré dynamiquement à partir de la réponse de `GET /settings` (un champ par paramètre, avec l'unité et la description en aide), et un lien de navigation « Paramètres » ajouté à la section Gestion (réservée au propriétaire) des 8 pages existantes ayant une barre latérale.

**Tech Stack:** Laravel 12 (validation dynamique par schéma), PHPUnit (`php artisan test`), frontend vanilla JS existant (`api.js`, `app.js`, design system Phase A).

## Global Constraints

- Cahier des charges : « paramètres système (seuils remise, écart caisse, etc.) non éditables via l'UI » — gap identifié à l'audit initial, comblé par ce module.
- Les 5 seuils concernés, avec leur clé exacte, leur usage et leur valeur par défaut actuelle (à ne PAS changer, uniquement rendre éditables) :
  | Clé | Usage | Défaut | Unité |
  |---|---|---|---|
  | `remise_max_sans_auth` | `SaleController::store()` — au-delà, une remise nécessite l'autorisation du propriétaire | 10 | % |
  | `remboursement_max` | `SaleController::refund()` — au-delà, un remboursement nécessite l'autorisation du propriétaire | 50000 | FCFA |
  | `sortie_stock_max` | `StockController::storeExit()` — au-delà, une sortie de stock nécessite l'autorisation du propriétaire | 20 | unités |
  | `ecart_caisse_alerte` | `CashSessionController`, `DashboardController`, `ReportController` — écart de caisse déclenchant une alerte | 2000 | FCFA |
  | `peremption_alerte_jours` | `DashboardController`, `StockController` — jours avant péremption déclenchant une alerte | 7 | jours |
- Réservé au rôle `proprietaire` (mêmes seuils que les autres réglages sensibles de l'application).
- Le modèle `Setting` (`app/Models/Setting.php`) et sa table sont réutilisés tels quels (`getValue()`/`setValue()`, cache 300s déjà invalidé par `setValue()`) — ne pas les modifier.
- Ne PAS créer de mécanisme de paramètres génériques arbitraires : seuls ces 5 paramètres, connus et bornés, sont éditables (pas de clé libre). Toute clé hors de cette liste dans une requête `PUT` est rejetée.
- Traçabilité : un seul appel `activity_log()` par requête `PUT` (même convention que le module B3 — pas un appel par paramètre modifié).
- Branche de travail : `feat/b4-parametres-systeme`, créée depuis `master`. Un commit par tâche. Ne pas pousser vers origin sans demande du client.
- Tests : `cd backend && php artisan test` doit passer en entier (30 tests existants + les nouveaux) à la fin de chaque tâche.
- Ne PAS toucher : `app/Models/Setting.php`, la logique métier qui consomme ces seuils (`SaleController`, `StockController`, `CashSessionController`, `DashboardController`, `ReportController` restent inchangés — ils continueront de lire via `Setting::getValue()`, qui reflétera automatiquement les nouvelles valeurs).

## File Structure

- **Create:** `backend/app/Http/Controllers/Api/SettingController.php` — `index()` (GET) et `update()` (PUT), schéma statique des 5 paramètres.
- **Modify:** `backend/routes/api.php` — nouveau groupe `role:proprietaire` → `prefix('settings')`.
- **Create:** `backend/tests/Feature/SettingControllerTest.php`.
- **Create:** `frontend/settings.html` — nouvelle page.
- **Modify:** `frontend/dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html`, `profile.html` — ajout du lien de navigation « Paramètres » (8 fichiers, `login.html` n'a pas de barre latérale).

---

### Task 1: Endpoint `GET/PUT /settings` (TDD)

**Files:**
- Create: `backend/app/Http/Controllers/Api/SettingController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/SettingControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Setting` (`getValue`, `setValue`), `tests/Support/CreatesShopData.php` (`makeUser`).
- Produces :
  - `GET /api/settings` → `{success, message, data: [{key, label, description, unit, value, default, min, max}, ...]}` (5 éléments, un par paramètre du schéma, dans l'ordre du tableau `SCHEMA`).
  - `PUT /api/settings` — payload `{ [key]: number, ... }` (un sous-ensemble des 5 clés, chacune optionnelle) → même forme de réponse que `GET`, reflétant l'état après écriture. Consommé par le frontend (Task 2) avec ces noms de champs exacts.

- [ ] **Step 1: Écrire les tests qui échouent**

Créer `backend/tests/Feature/SettingControllerTest.php` :
```php
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

    public function test_les_5_parametres_sont_exposes_avec_leurs_valeurs_par_defaut(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/settings');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(5, $data);

        $byKey = collect($data)->keyBy('key');
        $this->assertSame(10,    $byKey['remise_max_sans_auth']['value']);
        $this->assertSame('%',   $byKey['remise_max_sans_auth']['unit']);
        $this->assertSame(50000, $byKey['remboursement_max']['value']);
        $this->assertSame(20,    $byKey['sortie_stock_max']['value']);
        $this->assertSame(2000,  $byKey['ecart_caisse_alerte']['value']);
        $this->assertSame(7,     $byKey['peremption_alerte_jours']['value']);
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
```

- [ ] **Step 2: Vérifier l'échec**

Run: `cd backend && php artisan test --filter=SettingControllerTest`
Attendu : FAIL — 404 (route inexistante).

- [ ] **Step 3: Ajouter la route**

Dans `backend/routes/api.php`, ajouter l'import (ordre alphabétique, après `SaleController`) :
```php
use App\Http\Controllers\Api\SettingController;
```

Ajouter le groupe de routes, juste après le groupe `users` existant (avant le `});` de fermeture du groupe `auth:sanctum`) :
```php
    // Paramètres système — propriétaire uniquement
    Route::middleware('role:proprietaire')->prefix('settings')->group(function () {
        Route::get('/', [SettingController::class, 'index']);
        Route::put('/', [SettingController::class, 'update']);
    });
```

- [ ] **Step 4: Créer le contrôleur**

`backend/app/Http/Controllers/Api/SettingController.php` :
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    private const SCHEMA = [
        'remise_max_sans_auth' => [
            'label'       => 'Remise max. sans autorisation',
            'description' => "Au-delà de ce pourcentage de remise sur une vente, l'autorisation du propriétaire est requise.",
            'unit'        => '%',
            'default'     => 10,
            'min'         => 0,
            'max'         => 100,
        ],
        'remboursement_max' => [
            'label'       => 'Remboursement max. sans autorisation',
            'description' => "Au-delà de ce montant, un remboursement nécessite l'autorisation du propriétaire.",
            'unit'        => 'FCFA',
            'default'     => 50000,
            'min'         => 0,
            'max'         => null,
        ],
        'sortie_stock_max' => [
            'label'       => 'Sortie de stock max. sans autorisation',
            'description' => "Au-delà de cette quantité, une sortie de stock nécessite l'autorisation du propriétaire.",
            'unit'        => 'unités',
            'default'     => 20,
            'min'         => 1,
            'max'         => null,
        ],
        'ecart_caisse_alerte' => [
            'label'       => "Seuil d'alerte écart de caisse",
            'description' => "Au-delà de cet écart (valeur absolue) entre le montant théorique et le montant compté à la fermeture de caisse, une alerte est déclenchée.",
            'unit'        => 'FCFA',
            'default'     => 2000,
            'min'         => 0,
            'max'         => null,
        ],
        'peremption_alerte_jours' => [
            'label'       => 'Alerte péremption (jours avant)',
            'description' => "Nombre de jours avant la date de péremption à partir duquel un produit apparaît dans les alertes.",
            'unit'        => 'jours',
            'default'     => 7,
            'min'         => 0,
            'max'         => null,
        ],
    ];

    public function index(): JsonResponse
    {
        return $this->success($this->currentValues());
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->all();

        foreach (array_keys($payload) as $key) {
            if (!array_key_exists($key, self::SCHEMA)) {
                return $this->error("Paramètre inconnu : {$key}.", 422);
            }
        }

        $rules = [];
        foreach (self::SCHEMA as $key => $def) {
            $rule = 'sometimes|integer|min:' . $def['min'];
            if ($def['max'] !== null) {
                $rule .= '|max:' . $def['max'];
            }
            $rules[$key] = $rule;
        }

        $validated = $request->validate($rules);

        if (empty($validated)) {
            return $this->error('Aucun paramètre à mettre à jour.', 422);
        }

        foreach ($validated as $key => $value) {
            Setting::setValue($key, (string) $value);
        }

        activity_log($request->user()->id, 'modification_parametres', null, null, [
            'changes' => $validated,
        ]);

        return $this->success($this->currentValues(), 'Paramètres mis à jour.');
    }

    private function currentValues(): array
    {
        $result = [];
        foreach (self::SCHEMA as $key => $def) {
            $result[] = [
                'key'         => $key,
                'label'       => $def['label'],
                'description' => $def['description'],
                'unit'        => $def['unit'],
                'value'       => (int) Setting::getValue($key, $def['default']),
                'default'     => $def['default'],
                'min'         => $def['min'],
                'max'         => $def['max'],
            ];
        }

        return $result;
    }

    private function success(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null], $status);
    }
}
```

- [ ] **Step 5: Vérifier le passage**

Run: `php artisan test --filter=SettingControllerTest` — Attendu : 6 PASS.
Run: `php artisan test` — Attendu : tous PASS (36 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/SettingController.php routes/api.php tests/Feature/SettingControllerTest.php
git commit -m "feat: endpoint GET/PUT settings pour les 5 parametres systeme"
```

---

### Task 2: Frontend — page Paramètres + navigation

**Files:**
- Create: `frontend/settings.html`
- Modify: `frontend/dashboard.html`, `frontend/products.html`, `frontend/stock.html`, `frontend/inventory-count.html`, `frontend/pos.html`, `frontend/reports.html`, `frontend/users.html`, `frontend/profile.html`

**Interfaces:**
- Consumes: `GET /settings`, `PUT /settings` (Task 1), helpers `api.get/put`, `toast`, `escHtml`, `openModal`/`closeModal` (non utilisés ici — page sans modal), `refreshIcons`, `initLayout`, `requireAuth`.
- Produces: rien (page terminale).

- [ ] **Step 1: Ajouter le lien de navigation sur les 8 pages existantes**

Dans **chacun** de `frontend/dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html`, `profile.html`, remplacer (bloc identique dans les 8 fichiers, présent une seule fois chacun) :
```html
      <span class="nav-icon-wrap"><i data-lucide="chart-column" class="icon"></i></span> <span class="nav-label">Rapports</span>
    </a>
    <div class="sidebar-section">Compte</div>
```
par :
```html
      <span class="nav-icon-wrap"><i data-lucide="chart-column" class="icon"></i></span> <span class="nav-label">Rapports</span>
    </a>
    <a href="settings.html" class="nav-item" data-role="proprietaire">
      <span class="nav-icon-wrap"><i data-lucide="settings" class="icon"></i></span> <span class="nav-label">Paramètres</span>
    </a>
    <div class="sidebar-section">Compte</div>
```

- [ ] **Step 2: Créer `frontend/settings.html`**

```html
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Paramètres — Boutique D</title>
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#2563EB">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@0.462.0/dist/umd/lucide.js" integrity="sha384-WBRt9V/J/erVtkEuP91HUFRv9MvHzFiFOp4/zTDp4xkcMG7aOeIv2asTV4yxFLWa" crossorigin="anonymous"></script>
  <link rel="stylesheet" href="css/app.css">
</head>
<body>
<div id="toast-container"></div>
<div class="sidebar-overlay"></div>
<div class="layout">
  <header class="topbar">
    <button class="topbar-menu-btn" id="menu-btn"><i data-lucide="menu" class="icon"></i></button>
    <div class="topbar-brand">
      <div class="brand-icon"><i data-lucide="shopping-bag" class="icon" style="width:15px;height:15px"></i></div>
      Boutique D
    </div>
    <div class="topbar-spacer"></div>
    <div class="topbar-user" id="topbar-user"></div>
    <button class="btn btn-ghost btn-sm" id="btn-logout">
      <i data-lucide="log-out" class="icon"></i> Déconnexion
    </button>
  </header>

  <nav class="sidebar">
    <div class="sidebar-section">Principal</div>
    <a href="dashboard.html" class="nav-item" data-role="proprietaire,gestionnaire">
      <span class="nav-icon-wrap"><i data-lucide="layout-grid" class="icon"></i></span> <span class="nav-label">Dashboard</span>
    </a>
    <a href="pos.html" class="nav-item">
      <span class="nav-icon-wrap"><i data-lucide="store" class="icon"></i></span> <span class="nav-label">Caisse (POS)</span>
    </a>
    <div class="sidebar-section" data-role="proprietaire,gestionnaire">Stock</div>
    <a href="stock.html" class="nav-item" data-role="proprietaire,gestionnaire">
      <span class="nav-icon-wrap"><i data-lucide="boxes" class="icon"></i></span> <span class="nav-label">Stock</span>
      <span class="nav-badge hidden" id="alert-badge">0</span>
    </a>
    <a href="products.html" class="nav-item" data-role="proprietaire,gestionnaire">
      <span class="nav-icon-wrap"><i data-lucide="tag" class="icon"></i></span> <span class="nav-label">Produits</span>
    </a>
    <div class="sidebar-section" data-role="proprietaire">Gestion</div>
    <a href="users.html" class="nav-item" data-role="proprietaire">
      <span class="nav-icon-wrap"><i data-lucide="users" class="icon"></i></span> <span class="nav-label">Utilisateurs</span>
    </a>
    <a href="reports.html" class="nav-item" data-role="proprietaire">
      <span class="nav-icon-wrap"><i data-lucide="chart-column" class="icon"></i></span> <span class="nav-label">Rapports</span>
    </a>
    <a href="settings.html" class="nav-item" data-role="proprietaire">
      <span class="nav-icon-wrap"><i data-lucide="settings" class="icon"></i></span> <span class="nav-label">Paramètres</span>
    </a>
    <div class="sidebar-section">Compte</div>
    <a href="profile.html" class="nav-item">
      <span class="nav-icon-wrap"><i data-lucide="user" class="icon"></i></span> <span class="nav-label">Profil</span>
    </a>
  </nav>

  <main class="main">
    <div class="page-title">
      <div class="page-icon" style="background:var(--accent)">
        <i data-lucide="settings" class="icon"></i>
      </div>
      Paramètres système
    </div>

    <div class="card" style="max-width:640px">
      <div class="card-header">
        <div class="card-title">
          <i data-lucide="sliders-horizontal" class="icon" style="color:var(--accent)"></i> Seuils métier
        </div>
      </div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:18px" id="settings-form"></div>
      <div class="modal-footer" style="justify-content:flex-end">
        <button class="btn btn-primary" id="btn-save-settings">
          <i data-lucide="check" class="icon"></i> Enregistrer
        </button>
      </div>
    </div>
  </main>
</div>

<script src="js/api.js"></script>
<script src="js/app.js"></script>
<script>
requireAuth();

let settings = [];

async function loadSettings() {
  const container = document.getElementById('settings-form');
  container.innerHTML = Array.from({ length: 5 }, () =>
    '<div class="skeleton-line" style="height:52px"></div>'
  ).join('');

  try {
    settings = await api.get('/settings');
    container.innerHTML = settings.map(s => `
      <div class="form-group">
        <label class="form-label" for="setting-${s.key}">${escHtml(s.label)}</label>
        <div class="flex gap-2 items-center">
          <input class="form-control" id="setting-${s.key}" type="number"
                 min="${s.min}" ${s.max !== null ? `max="${s.max}"` : ''} value="${s.value}" style="max-width:160px">
          <span class="text-muted font-sm">${escHtml(s.unit)}</span>
        </div>
        <span class="form-hint">${escHtml(s.description)}</span>
      </div>`).join('');
    refreshIcons();
  } catch (err) {
    toast(err.message, 'danger');
    container.innerHTML = `<div class="empty-state"><div class="icon-wrap"><i data-lucide="triangle-alert" class="icon"></i></div><p>Erreur de chargement</p></div>`;
    refreshIcons();
  }
}

async function saveSettings() {
  const payload = {};
  for (const s of settings) {
    const input = document.getElementById(`setting-${s.key}`);
    payload[s.key] = Number(input.value);
  }

  const btn = document.getElementById('btn-save-settings');
  btn.disabled = true;
  try {
    settings = await api.put('/settings', payload);
    toast('Paramètres mis à jour.', 'success');
  } catch (err) {
    toast(err.message, 'danger');
  } finally {
    btn.disabled = false;
  }
}

document.getElementById('btn-save-settings').addEventListener('click', saveSettings);

loadSettings();
</script>
</body>
</html>
```

- [ ] **Step 3: Vérification statique**

1. `grep -c "href=\"settings.html\"" frontend/*.html` → 1 dans chacun des 8 fichiers modifiés + 1 dans `settings.html` lui-même (auto-référence, normale — `initLayout()` détecte la page active via `location.pathname`).
2. `node --check` sur le JS inline de `settings.html`.
3. `grep -c "bi bi-" frontend/settings.html` → 0 (nouvelle page, uniquement Lucide).

- [ ] **Step 4: Vérification manuelle**

1. Serveurs démarrés, login `admin`/`admin123`.
2. Le lien « Paramètres » apparaît dans la section Gestion de la barre latérale sur toutes les pages sauf Login.
3. Ouvrir Paramètres : les 5 champs se chargent avec leurs valeurs actuelles (10, 50000, 20, 2000, 7), unité et description affichées sous chaque champ.
4. Modifier « Remise max. sans autorisation » à 15, cliquer Enregistrer → toast de succès.
5. Recharger la page → la valeur 15 est bien persistée.
6. Se connecter en tant que caissier → le lien Paramètres n'apparaît pas dans la barre latérale (déjà garanti par `initLayout()`/`data-role`, non modifié).
7. Faire une vente avec une remise de 20% en tant que caissier → le message d'erreur doit maintenant citer 15% (le nouveau seuil), preuve que le changement de paramètre est bien pris en compte par `SaleController` sans redéploiement.

- [ ] **Step 5: Commit**

```bash
git add frontend/settings.html frontend/dashboard.html frontend/products.html frontend/stock.html frontend/inventory-count.html frontend/pos.html frontend/reports.html frontend/users.html frontend/profile.html
git commit -m "feat: page parametres systeme + navigation"
```

---

### Task 3: Vérification finale du module

**Files:** aucun changement de code — vérification uniquement.

- [ ] **Step 1: Suite complète**

Run: `cd backend && php artisan test` — Attendu : tous PASS (36 tests, sortie propre).

- [ ] **Step 2: Non-régression**

Vérifier que les 5 contrôleurs consommateurs (`SaleController`, `StockController`, `CashSessionController`, `DashboardController`, `ReportController`) n'ont subi aucune modification (`git diff master --stat` sur la branche ne doit lister aucun de ces fichiers).

- [ ] **Step 3: Vérification croisée**

Modifier `ecart_caisse_alerte` à une valeur basse (ex: 100) via la page Paramètres, puis clôturer une session de caisse avec un écart supérieur à 100 : l'alerte doit apparaître sur le Dashboard alors qu'elle n'apparaissait pas avant le changement (avec l'ancien seuil de 2000).

- [ ] **Step 4: Commit (si des ajustements ont été faits)**

Sinon, rien à commit — cette tâche clôt le module avant la revue finale de branche.
