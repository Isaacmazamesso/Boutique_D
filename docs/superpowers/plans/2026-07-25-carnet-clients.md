# Carnet de clients — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Poser la fondation « clients » : une entité client (nom, téléphone unique, note), un CRUD réservé propriétaire+gestionnaire (création ouverte au caissier), une fiche client avec historique d'achat, et un rattachement optionnel et discret d'un client à une vente à la caisse — sans jamais toucher au flux de vente anonyme existant.

**Architecture:** Table `customers` + colonne `sales.customer_id` nullable (additif). `CustomerController` calqué sur `UserController`. Page `clients.html` calquée sur `users.html`, avec une fiche détaillée. Sélecteur client discret dans `pos.html`. L'ajout de `customer_id` à `SaleController::store` est strictement additif (patron du champ `uuid` de B6).

**Tech Stack:** Laravel 12 (migrations, Eloquent, spatie/permission), PHPUnit (`php artisan test`), JavaScript vanilla + design system Phase A.

## Global Constraints

- Spec `docs/superpowers/specs/2026-07-25-carnet-clients-design.md`.
- **Le flux de vente anonyme reste strictement inchangé.** L'ajout de `customer_id` à `store()` est additif : une règle de validation, un champ à la création, une clé dans `formatSale` — aucune ligne existante de `store()` supprimée ou réordonnée.
- **Hors périmètre :** aucune logique de crédit ; le chemin hors-ligne de `processSale()` (B6) reste inchangé (pas de `customer_id` sur une vente hors-ligne).
- Fiche client : `name` obligatoire, `phone` obligatoire **unique**, `note` optionnelle.
- Accès : `GET/PUT/DELETE /customers` et `GET /customers/{id}` → `role:proprietaire|gestionnaire`. `POST /customers` → `role:proprietaire|gestionnaire|caissier` (seule action ouverte au caissier).
- Suppression bloquée (422) si le client a des ventes — patron `UserController::destroy` / `ProductController::destroy`.
- Réponses `{success, message, data}`. Montants entiers FCFA. Branche `feat/carnet-clients` depuis `master`. Un commit par tâche. Ne pas pousser vers origin sans demande. `php artisan test` vert en entier (91 existants + nouveaux) à la fin de chaque tâche. Après toute migration : `php artisan migrate --force` sur la base de dev PostgreSQL.
- Réutiliser `tests/Support/CreatesShopData.php` (`makeUser`, `makeProduct`, `openSession`, `makeSaleViaApi`) sans le modifier ; ajouter les fixtures clients en ligne dans les tests.

## File Structure

- **Create:** `backend/database/migrations/2026_07_25_100000_create_customers_table.php`
- **Create:** `backend/database/migrations/2026_07_25_100100_add_customer_id_to_sales_table.php`
- **Create:** `backend/app/Models/Customer.php`
- **Modify:** `backend/app/Models/Sale.php` — `'customer_id'` au `$fillable` + relation `customer()`.
- **Create:** `backend/app/Http/Controllers/Api/CustomerController.php`
- **Modify:** `backend/routes/api.php` — groupe `customers`.
- **Modify:** `backend/app/Http/Controllers/Api/SaleController.php` — `customer_id` (validation + création) + `'customer'` dans `formatSale`.
- **Create:** `backend/tests/Feature/CustomerCrudTest.php`, `backend/tests/Feature/SaleWithCustomerTest.php`
- **Create:** `frontend/clients.html`
- **Modify:** `frontend/dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html`, `profile.html`, `settings.html`, `vente-tablette.html` — lien de nav « Clients » (10 pages).
- **Modify:** `frontend/pos.html` — sélecteur client.

---

### Task 1: Backend — modèle client + CRUD (TDD)

**Files:**
- Create: migration `2026_07_25_100000_create_customers_table.php`, modèle `Customer.php`, `CustomerController.php`, test `CustomerCrudTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Consumes: `CreatesShopData::makeUser`, `Sanctum`.
- Produces : `GET /api/customers` (liste, `?search=`), `POST /api/customers` (créer), `GET /api/customers/{customer}` (fiche : coordonnées + `ventes[]` + `total_depense`), `PUT /api/customers/{customer}`, `DELETE /api/customers/{customer}`. Modèle `Customer` (`name`, `phone`, `note`, relation `sales()`, méthode `hasSales()`). Consommé par Tasks 2–4.

- [ ] **Step 1: Écrire les tests qui échouent**

`backend/tests/Feature/CustomerCrudTest.php` :
```php
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
            ->assertStatus(422);
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
```

- [ ] **Step 2: Vérifier l'échec**

Run: `cd backend && php artisan test --filter=CustomerCrudTest`
Attendu : FAIL (routes/table inexistantes).

- [ ] **Step 3: Migration `customers`**

`backend/database/migrations/2026_07_25_100000_create_customers_table.php` :
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
```

- [ ] **Step 4: Modèle `Customer`**

`backend/app/Models/Customer.php` :
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name', 'phone', 'note'];

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function hasSales(): bool
    {
        return $this->sales()->exists();
    }
}
```

- [ ] **Step 5: `CustomerController`**

`backend/app/Http/Controllers/Api/CustomerController.php` :
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customers = Customer::withCount('sales')
            ->when($request->search, function ($q) use ($request) {
                $term = $request->search;
                $q->where(fn($sub) => $sub->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"));
            })
            ->orderBy('name')
            ->get()
            ->map(fn($c) => $this->formatCustomer($c));

        return $this->success($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'  => 'required|string|max:150',
            'phone' => 'required|string|max:30|unique:customers,phone',
            'note'  => 'nullable|string',
        ]);

        $customer = Customer::create($request->only(['name', 'phone', 'note']));

        activity_log($request->user()->id, 'creation_client', 'Customer', $customer->id, [
            'name' => $customer->name,
        ]);

        return $this->success($this->formatCustomer($customer->loadCount('sales')), 'Client créé.', 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $ventes = $customer->sales()
            ->latest()
            ->get()
            ->map(fn($s) => [
                'id'             => $s->id,
                'receipt_number' => $s->receipt_number,
                'date'           => $s->created_at->format('d/m/Y H:i'),
                'total'          => $s->total,
            ]);

        return $this->success([
            'id'            => $customer->id,
            'name'          => $customer->name,
            'phone'         => $customer->phone,
            'note'          => $customer->note,
            'nb_ventes'     => $ventes->count(),
            'total_depense' => (int) $customer->sales()->sum('total'),
            'ventes'        => $ventes,
        ]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $request->validate([
            'name'  => 'sometimes|string|max:150',
            'phone' => ['sometimes', 'string', 'max:30', Rule::unique('customers', 'phone')->ignore($customer->id)],
            'note'  => 'nullable|string',
        ]);

        $customer->update($request->only(['name', 'phone', 'note']));

        activity_log($request->user()->id, 'modification_client', 'Customer', $customer->id);

        return $this->success($this->formatCustomer($customer->loadCount('sales')), 'Client mis à jour.');
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        if ($customer->hasSales()) {
            return $this->error('Impossible : ce client a des ventes enregistrées.', 422);
        }

        activity_log($request->user()->id, 'suppression_client', 'Customer', $customer->id, [
            'name' => $customer->name,
        ]);

        $customer->delete();

        return $this->success(null, 'Client supprimé.');
    }

    private function formatCustomer(Customer $customer): array
    {
        return [
            'id'        => $customer->id,
            'name'      => $customer->name,
            'phone'     => $customer->phone,
            'note'      => $customer->note,
            'nb_ventes' => $customer->sales_count ?? $customer->sales()->count(),
        ];
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

- [ ] **Step 6: Routes**

Dans `backend/routes/api.php`, ajouter l'import (ordre alphabétique, après `CategoryController`) :
```php
use App\Http\Controllers\Api\CustomerController;
```

Dans le groupe `auth:sanctum`, ajouter (par exemple juste après le groupe `users`) :
```php
    // Clients — gestion : proprietaire + gestionnaire ; creation ouverte au caissier (saisie a la volee)
    Route::prefix('customers')->group(function () {
        Route::post('/', [CustomerController::class, 'store'])->middleware('role:proprietaire|gestionnaire|caissier');
        Route::middleware('role:proprietaire|gestionnaire')->group(function () {
            Route::get('/', [CustomerController::class, 'index']);
            Route::get('{customer}', [CustomerController::class, 'show']);
            Route::put('{customer}', [CustomerController::class, 'update']);
            Route::delete('{customer}', [CustomerController::class, 'destroy']);
        });
    });
```

- [ ] **Step 7: Vérifier le passage**

Run: `php artisan test --filter=CustomerCrudTest` — Attendu : 7 PASS.
Run: `php artisan test` — Attendu : tous PASS (98 tests).

- [ ] **Step 8: Commit + migration dev**

```bash
git add backend/database/migrations/2026_07_25_100000_create_customers_table.php backend/app/Models/Customer.php backend/app/Http/Controllers/Api/CustomerController.php backend/routes/api.php backend/tests/Feature/CustomerCrudTest.php
git commit -m "feat: entite client + CRUD (creation ouverte au caissier, gestion proprietaire/gestionnaire)"
```
Puis `php artisan migrate --force` (applique la table `customers` à la base de dev PostgreSQL).

---

### Task 2: Backend — rattachement d'un client à une vente (TDD, additif)

**Files:**
- Create: migration `2026_07_25_100100_add_customer_id_to_sales_table.php`
- Modify: `backend/app/Models/Sale.php`, `backend/app/Http/Controllers/Api/SaleController.php`
- Test: `backend/tests/Feature/SaleWithCustomerTest.php`

**Interfaces:**
- Consumes: `Customer` (Task 1), `CreatesShopData` (`makeUser`, `makeProduct`, `openSession`).
- Produces : `POST /sales` accepte `customer_id` optionnel (`nullable|exists:customers,id`) ; `formatSale` renvoie `'customer' => <nom|null>`. La colonne `sales.customer_id` alimente la fiche client (`GET /customers/{id}`).

- [ ] **Step 1: Écrire les tests qui échouent**

`backend/tests/Feature/SaleWithCustomerTest.php` :
```php
<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class SaleWithCustomerTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_vente_rattachee_a_un_client(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);
        $customer = Customer::create(['name' => 'Awa Diop', 'phone' => '770000010']);

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 1000,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'customer_id' => $customer->id,
        ]);

        $response->assertCreated();
        $this->assertSame('Awa Diop', $response->json('data.customer'));
        $this->assertSame($customer->id, Sale::first()->customer_id);
    }

    public function test_vente_anonyme_inchangee(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 500,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertCreated();
        $this->assertNull(Sale::first()->customer_id);
        $this->assertNull($response->json('data.customer'));
    }

    public function test_customer_id_inexistant_refuse(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 500,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'customer_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_fiche_client_liste_ses_ventes_et_total(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 100);
        $customer = Customer::create(['name' => 'Client Fidele', 'phone' => '770000011']);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sales', [
            'sale_type' => 'detail', 'payment_method' => 'especes', 'amount_paid' => 1000,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'customer_id' => $customer->id,
        ])->assertCreated();

        $gestionnaire = $this->makeUser('gestionnaire');
        Sanctum::actingAs($gestionnaire);
        $data = $this->getJson("/api/customers/{$customer->id}")->assertOk()->json('data');

        $this->assertSame(1, $data['nb_ventes']);
        $this->assertSame(1000, $data['total_depense']);
        $this->assertCount(1, $data['ventes']);
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `php artisan test --filter=SaleWithCustomerTest`
Attendu : FAIL (colonne `customer_id` inexistante, `data.customer` absent).

- [ ] **Step 3: Migration `sales.customer_id`**

`backend/database/migrations/2026_07_25_100100_add_customer_id_to_sales_table.php` :
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('vendor_id')->constrained('customers');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
```

- [ ] **Step 4: Modèle `Sale`**

Dans `backend/app/Models/Sale.php`, ajouter `'customer_id'` au `$fillable` (après `'vendor_id'`) et la relation. Le `$fillable` devient :
```php
    protected $fillable = [
        'receipt_number', 'sync_uuid', 'cashier_id', 'vendor_id', 'customer_id', 'cash_session_id', 'status',
        'sale_type', 'payment_method', 'mobile_money_number',
        'subtotal', 'discount_type', 'discount_value', 'total',
        'amount_paid', 'change_given', 'notes',
    ];
```
Et ajouter la relation (après `vendor()`) :
```php
    public function customer() { return $this->belongsTo(Customer::class); }
```

- [ ] **Step 5: `SaleController::store` + `formatSale` (additif)**

Dans `store()`, ajouter la règle de validation `customer_id` (après `'notes'`, avant `'uuid'` ou après — peu importe, dans le tableau `$request->validate`) :
```php
            'notes'                  => 'nullable|string',
            'uuid'                   => 'nullable|uuid',
            'customer_id'            => 'nullable|exists:customers,id',
        ]);
```
Dans le `Sale::create([...])` de `store()`, ajouter `'customer_id'` (après `'vendor_id'`) :
```php
                'cashier_id'       => $request->user()->id,
                'vendor_id'        => $request->vendor_id,
                'customer_id'      => $request->customer_id,
```
Dans `formatSale()`, ajouter la clé `'customer'` (après `'vendor'`) — et charger la relation dans les `->load(...)` de `store()` (`'customer:id,name'`). La clé :
```php
            'cashier'        => $sale->cashier?->name,
            'vendor'         => $sale->vendor?->name,
            'customer'       => $sale->customer?->name,
```
Dans `store()`, le `->load([...])` final passe de `['items.product', 'cashier:id,name']` à `['items.product', 'cashier:id,name', 'customer:id,name']`.

- [ ] **Step 6: Vérifier le passage**

Run: `php artisan test --filter=SaleWithCustomerTest` — Attendu : 4 PASS.
Run: `php artisan test` — Attendu : tous PASS (102 tests). Vérifier notamment que les tests de vente existants (`SaleFlowTest`, `SaleStoreTest`, `SaleSyncDedupTest`, `PendingSale*`) restent verts (non-régression du chemin de vente).

- [ ] **Step 7: Commit + migration dev**

```bash
git add backend/database/migrations/2026_07_25_100100_add_customer_id_to_sales_table.php backend/app/Models/Sale.php backend/app/Http/Controllers/Api/SaleController.php backend/tests/Feature/SaleWithCustomerTest.php
git commit -m "feat: rattachement optionnel d'un client a une vente (additif, vente anonyme inchangee)"
```
Puis `php artisan migrate --force`.

---

### Task 3: Frontend — page `clients.html` + navigation

**Files:**
- Create: `frontend/clients.html`
- Modify: `frontend/dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html`, `profile.html`, `settings.html`, `vente-tablette.html`

**Interfaces:**
- Consumes: `GET/POST/PUT/DELETE /customers`, `GET /customers/{id}` (Tasks 1–2), helpers `api.*`, `toast`, `escHtml`, `fmt`, `renderTable`, `showTableSkeleton`, `openModal`/`closeModal`, `refreshIcons`, `requireAuth`, `hasRole`.
- Produces: rien (page terminale).

- [ ] **Step 1: Lien de nav « Clients » sur les 10 pages existantes**

Dans **chacun** de `frontend/dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html`, `profile.html`, `settings.html`, `vente-tablette.html`, remplacer (bloc identique dans les 10 fichiers) :
```html
    <div class="sidebar-section" data-role="proprietaire">Gestion</div>
    <a href="users.html" class="nav-item" data-role="proprietaire">
      <span class="nav-icon-wrap"><i data-lucide="users" class="icon"></i></span> <span class="nav-label">Utilisateurs</span>
    </a>
```
par :
```html
    <div class="sidebar-section" data-role="proprietaire,gestionnaire">Gestion</div>
    <a href="clients.html" class="nav-item" data-role="proprietaire,gestionnaire">
      <span class="nav-icon-wrap"><i data-lucide="contact" class="icon"></i></span> <span class="nav-label">Clients</span>
    </a>
    <a href="users.html" class="nav-item" data-role="proprietaire">
      <span class="nav-icon-wrap"><i data-lucide="users" class="icon"></i></span> <span class="nav-label">Utilisateurs</span>
    </a>
```
(Le libellé de section « Gestion » passe à `proprietaire,gestionnaire` pour qu'un gestionnaire voie l'en-tête au-dessus de « Clients » ; « Utilisateurs », « Rapports », « Paramètres » gardent `data-role="proprietaire"` et restent donc masqués pour un gestionnaire.)

- [ ] **Step 2: Créer `frontend/clients.html`**

```html
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Clients — Boutique D</title>
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
    <a href="vente-tablette.html" class="nav-item">
      <span class="nav-icon-wrap"><i data-lucide="tablet-smartphone" class="icon"></i></span> <span class="nav-label">Vente (tablette)</span>
    </a>
    <div class="sidebar-section" data-role="proprietaire,gestionnaire">Stock</div>
    <a href="stock.html" class="nav-item" data-role="proprietaire,gestionnaire">
      <span class="nav-icon-wrap"><i data-lucide="boxes" class="icon"></i></span> <span class="nav-label">Stock</span>
      <span class="nav-badge hidden" id="alert-badge">0</span>
    </a>
    <a href="products.html" class="nav-item" data-role="proprietaire,gestionnaire">
      <span class="nav-icon-wrap"><i data-lucide="tag" class="icon"></i></span> <span class="nav-label">Produits</span>
    </a>
    <div class="sidebar-section" data-role="proprietaire,gestionnaire">Gestion</div>
    <a href="clients.html" class="nav-item" data-role="proprietaire,gestionnaire">
      <span class="nav-icon-wrap"><i data-lucide="contact" class="icon"></i></span> <span class="nav-label">Clients</span>
    </a>
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
        <i data-lucide="contact" class="icon"></i>
      </div>
      Clients
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <i data-lucide="users" class="icon" style="color:var(--accent)"></i> Liste des clients
        </div>
        <button class="btn btn-sm btn-primary" id="btn-add-client">
          <i data-lucide="user-plus" class="icon"></i> Nouveau client
        </button>
      </div>
      <div class="card-body" style="padding-bottom:0">
        <div style="position:relative;max-width:280px">
          <i data-lucide="search" class="icon" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-subtle);pointer-events:none"></i>
          <input class="form-control" id="client-search" placeholder="Rechercher (nom, téléphone)…" style="padding-left:34px">
        </div>
      </div>
      <div class="table-wrap" style="margin-top:1px">
        <table>
          <thead>
            <tr><th>Nom</th><th>Téléphone</th><th class="text-right">Ventes</th><th></th></tr>
          </thead>
          <tbody id="clients-tbody"></tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<!-- Modal création/modification -->
<div class="modal-backdrop" id="modal-client">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <span class="modal-title" id="client-modal-title"><i data-lucide="user-plus" class="icon" style="color:var(--accent)"></i> Nouveau client</span>
      <button class="modal-close" data-dismiss><i data-lucide="x" class="icon"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="client-id">
      <div class="form-group">
        <label class="form-label">Nom *</label>
        <input class="form-control" id="client-name" type="text" placeholder="Ex: Awa Diop">
      </div>
      <div class="form-group">
        <label class="form-label">Téléphone *</label>
        <input class="form-control" id="client-phone" type="tel" placeholder="Ex: 770000000">
      </div>
      <div class="form-group">
        <label class="form-label">Note</label>
        <textarea class="form-control" id="client-note" rows="2"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" data-dismiss>Annuler</button>
      <button class="btn btn-primary" id="btn-save-client"><i data-lucide="check" class="icon"></i> Enregistrer</button>
    </div>
  </div>
</div>

<!-- Modal fiche détaillée -->
<div class="modal-backdrop" id="modal-client-detail">
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <span class="modal-title"><i data-lucide="contact" class="icon" style="color:var(--accent)"></i> Fiche client</span>
      <button class="modal-close" data-dismiss><i data-lucide="x" class="icon"></i></button>
    </div>
    <div class="modal-body" id="client-detail-body"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" id="btn-edit-client-detail"><i data-lucide="square-pen" class="icon"></i> Modifier</button>
      <button class="btn btn-danger" id="btn-delete-client-detail"><i data-lucide="trash-2" class="icon"></i> Supprimer</button>
    </div>
  </div>
</div>

<script src="js/api.js"></script>
<script src="js/app.js"></script>
<script src="js/offline.js"></script>
<script>
requireAuth();

let clients = [];
let currentDetailId = null;

async function loadClients(query = '') {
  showTableSkeleton('clients-tbody', 4);
  try {
    const params = query ? `?search=${encodeURIComponent(query)}` : '';
    clients = await api.get(`/customers${params}`);
    renderTable('clients-tbody', clients.map(c => `
      <tr data-id="${c.id}" style="cursor:pointer">
        <td><strong>${escHtml(c.name)}</strong></td>
        <td>${escHtml(c.phone)}</td>
        <td class="text-right">${c.nb_ventes}</td>
        <td class="text-right"><i data-lucide="chevron-right" class="icon" style="color:var(--text-subtle)"></i></td>
      </tr>`), 'Aucun client', 'contact');
    document.querySelectorAll('#clients-tbody tr[data-id]').forEach(tr => {
      tr.addEventListener('click', () => openClientDetail(Number(tr.dataset.id)));
    });
  } catch (err) {
    toast(err.message, 'danger');
    renderTable('clients-tbody', [], 'Erreur de chargement', 'triangle-alert');
  }
}

function openClientModal(client = null) {
  document.getElementById('client-id').value    = client?.id ?? '';
  document.getElementById('client-name').value  = client?.name ?? '';
  document.getElementById('client-phone').value = client?.phone ?? '';
  document.getElementById('client-note').value  = client?.note ?? '';
  document.getElementById('client-modal-title').innerHTML = client
    ? '<i data-lucide="square-pen" class="icon" style="color:var(--accent)"></i> Modifier client'
    : '<i data-lucide="user-plus" class="icon" style="color:var(--accent)"></i> Nouveau client';
  openModal('modal-client');
  refreshIcons();
}

async function saveClient() {
  const id    = document.getElementById('client-id').value;
  const body  = {
    name:  document.getElementById('client-name').value.trim(),
    phone: document.getElementById('client-phone').value.trim(),
    note:  document.getElementById('client-note').value.trim() || null,
  };
  if (!body.name || !body.phone) { toast('Nom et téléphone sont obligatoires.', 'warning'); return; }

  const btn = document.getElementById('btn-save-client');
  btn.disabled = true;
  try {
    if (id) await api.put(`/customers/${id}`, body);
    else    await api.post('/customers', body);
    toast(id ? 'Client mis à jour.' : 'Client créé.', 'success');
    closeModal('modal-client');
    loadClients(document.getElementById('client-search').value.trim());
  } catch (err) {
    toast(err.message, 'danger');
  } finally {
    btn.disabled = false;
  }
}

async function openClientDetail(id) {
  currentDetailId = id;
  const body = document.getElementById('client-detail-body');
  body.innerHTML = '<div class="skeleton-line" style="height:60px"></div>';
  openModal('modal-client-detail');
  try {
    const c = await api.get(`/customers/${id}`);
    const ventes = (c.ventes || []).map(v => `
      <tr><td>${escHtml(v.receipt_number)}</td><td>${escHtml(v.date)}</td><td class="text-right font-bold">${fmt(v.total)}</td></tr>`).join('');
    body.innerHTML = `
      <div class="flex gap-4 mb-4">
        <div><div class="form-hint">Nom</div><div class="font-bold">${escHtml(c.name)}</div></div>
        <div><div class="form-hint">Téléphone</div><div class="font-bold">${escHtml(c.phone)}</div></div>
        <div><div class="form-hint">Total dépensé</div><div class="font-bold text-primary">${fmt(c.total_depense)}</div></div>
      </div>
      ${c.note ? `<div class="alert" style="background:var(--border-soft)"><i data-lucide="sticky-note" class="icon"></i> ${escHtml(c.note)}</div>` : ''}
      <div class="card-title mb-2" style="margin-top:6px"><i data-lucide="receipt" class="icon"></i> Historique (${c.nb_ventes})</div>
      <div class="table-wrap" style="max-height:280px;overflow-y:auto">
        <table><thead><tr><th>N° Reçu</th><th>Date</th><th class="text-right">Total</th></tr></thead>
        <tbody>${ventes || '<tr><td colspan="3" class="text-center text-muted" style="padding:16px">Aucune vente</td></tr>'}</tbody></table>
      </div>`;
    refreshIcons();
  } catch (err) {
    toast(err.message, 'danger');
    closeModal('modal-client-detail');
  }
}

async function deleteClient() {
  if (!currentDetailId) return;
  if (!confirm('Supprimer ce client ?')) return;
  try {
    await api.delete(`/customers/${currentDetailId}`);
    toast('Client supprimé.', 'success');
    closeModal('modal-client-detail');
    loadClients(document.getElementById('client-search').value.trim());
  } catch (err) {
    toast(err.message, 'danger');
  }
}

document.getElementById('btn-add-client').addEventListener('click', () => openClientModal());
document.getElementById('btn-save-client').addEventListener('click', saveClient);
document.getElementById('btn-delete-client-detail').addEventListener('click', deleteClient);
document.getElementById('btn-edit-client-detail').addEventListener('click', () => {
  const c = clients.find(x => x.id === currentDetailId);
  closeModal('modal-client-detail');
  openClientModal(c);
});
let searchTimer;
document.getElementById('client-search').addEventListener('input', e => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadClients(e.target.value.trim()), 300);
});

loadClients();
</script>
</body>
</html>
```

- [ ] **Step 3: Vérification statique**

1. `grep -c "href=\"clients.html\"" frontend/*.html` → 1 dans chacun des 10 fichiers modifiés + 1 dans `clients.html`.
2. `node --check` sur le JS inline de `clients.html`.
3. `grep -c "bi bi-" frontend/clients.html` → 0.

- [ ] **Step 4: Vérification manuelle**

1. Serveurs démarrés, `admin`/`admin123`. Le lien « Clients » apparaît dans la section Gestion.
2. Créer un client (nom + téléphone) → apparaît dans la liste. Recréer avec le même téléphone → message d'erreur (doublon).
3. Rechercher par nom puis par téléphone → filtre correct.
4. Cliquer un client → fiche détaillée (coordonnées, total dépensé, historique — vide au début).
5. Modifier depuis la fiche → nom mis à jour. Supprimer un client sans vente → retiré de la liste.

- [ ] **Step 5: Commit**

```bash
git add frontend/clients.html frontend/dashboard.html frontend/products.html frontend/stock.html frontend/inventory-count.html frontend/pos.html frontend/reports.html frontend/users.html frontend/profile.html frontend/settings.html frontend/vente-tablette.html
git commit -m "feat: page de gestion des clients + fiche detaillee + navigation"
```

---

### Task 4: Frontend — sélecteur client à la caisse

**Files:**
- Modify: `frontend/pos.html`

**Interfaces:**
- Consumes: `GET /customers?search=`, `POST /customers`, globales `cart`, `pendingSaleId`, fonctions existantes `renderCart`, `processSale`, `openModal`/`closeModal`, `toast`, `escHtml`, `fmt`, `refreshIcons`.
- Produces: variable globale `selectedCustomer` (`{id, name}` ou `null`) ; `processSale()` ajoute `customer_id` au corps seulement si `selectedCustomer` est non nul ET qu'on est en ligne.

- [ ] **Step 1: Champ client dans le panier**

Dans `frontend/pos.html`, dans la zone du panier (juste après l'en-tête `.cart-header`, avant `.cart-items`), ajouter :
```html
        <div class="cart-customer" id="cart-customer" style="padding:8px 12px;border-bottom:1px solid var(--border-soft)">
          <div id="customer-none" class="flex items-center gap-2">
            <i data-lucide="user" class="icon" style="color:var(--text-subtle)"></i>
            <input class="form-control" id="customer-search" placeholder="Client (optionnel)…" style="height:32px;font-size:.82rem;flex:1">
            <button class="btn btn-sm" id="btn-new-customer" title="Nouveau client"><i data-lucide="user-plus" class="icon"></i></button>
          </div>
          <div id="customer-results" style="max-height:120px;overflow-y:auto"></div>
          <div id="customer-selected" class="hidden flex items-center justify-between" style="background:var(--accent-soft);border-radius:8px;padding:5px 10px">
            <span class="font-sm font-bold" id="customer-selected-name"></span>
            <button class="btn btn-ghost btn-sm" id="btn-clear-customer"><i data-lucide="x" class="icon"></i></button>
          </div>
        </div>
```

- [ ] **Step 2: JS du sélecteur**

Ajouter dans le script inline de `pos.html` :
```javascript
let selectedCustomer = null;

function renderCustomerField() {
  const none = document.getElementById('customer-none');
  const sel  = document.getElementById('customer-selected');
  if (selectedCustomer) {
    none.classList.add('hidden');
    document.getElementById('customer-results').innerHTML = '';
    sel.classList.remove('hidden');
    document.getElementById('customer-selected-name').textContent = selectedCustomer.name;
  } else {
    none.classList.remove('hidden');
    sel.classList.add('hidden');
  }
  refreshIcons();
}

async function searchCustomers(term) {
  const box = document.getElementById('customer-results');
  if (!term.trim()) { box.innerHTML = ''; return; }
  try {
    const list = await api.get(`/customers?search=${encodeURIComponent(term.trim())}`);
    box.innerHTML = list.slice(0, 6).map(c => `
      <div class="customer-result" data-id="${c.id}" data-name="${escHtml(c.name)}" style="padding:6px 8px;cursor:pointer;border-radius:6px;font-size:.82rem">
        ${escHtml(c.name)} <span class="text-muted">· ${escHtml(c.phone)}</span>
      </div>`).join('') || '<div class="text-muted font-sm" style="padding:6px 8px">Aucun client — cliquez + pour en créer un</div>';
    box.querySelectorAll('.customer-result').forEach(el => {
      el.addEventListener('click', () => {
        selectedCustomer = { id: Number(el.dataset.id), name: el.dataset.name };
        document.getElementById('customer-search').value = '';
        renderCustomerField();
      });
    });
  } catch { /* silencieux : le champ client est optionnel */ }
}

async function createCustomerInline() {
  const name = prompt('Nom du client :');
  if (!name || !name.trim()) return;
  const phone = prompt('Téléphone :');
  if (!phone || !phone.trim()) return;
  try {
    const c = await api.post('/customers', { name: name.trim(), phone: phone.trim() });
    selectedCustomer = { id: c.id, name: c.name };
    document.getElementById('customer-search').value = '';
    renderCustomerField();
    toast('Client créé et rattaché.', 'success');
  } catch (err) {
    toast(err.message, 'danger');
  }
}

document.getElementById('btn-new-customer').addEventListener('click', createCustomerInline);
document.getElementById('btn-clear-customer').addEventListener('click', () => { selectedCustomer = null; renderCustomerField(); });
let custTimer;
document.getElementById('customer-search').addEventListener('input', e => {
  clearTimeout(custTimer);
  custTimer = setTimeout(() => searchCustomers(e.target.value), 300);
});
```

- [ ] **Step 3: Rattachement à l'encaissement (en ligne uniquement)**

Dans `processSale()`, dans le `body` de l'appel `POST /sales` (la branche EN LIGNE, pas la branche hors-ligne de B6), ajouter `customer_id` de façon conditionnelle. Le `body` construit passe de :
```javascript
  const body  = {
    sale_type:      saleType,
    payment_method: method,
    amount_paid:    amountPaid ?? total,
    items,
    ...(mobileNumber ? { mobile_money_number: mobileNumber } : {}),
    ...(discount.type ? { discount_type: discount.type, discount_value: discount.value } : {}),
  };
```
à :
```javascript
  const body  = {
    sale_type:      saleType,
    payment_method: method,
    amount_paid:    amountPaid ?? total,
    items,
    ...(mobileNumber ? { mobile_money_number: mobileNumber } : {}),
    ...(discount.type ? { discount_type: discount.type, discount_value: discount.value } : {}),
    ...(selectedCustomer ? { customer_id: selectedCustomer.id } : {}),
  };
```
(Ce `body` est utilisé par le chemin en ligne ET par la validation d'un panier vendeur ; les deux acceptent `customer_id` optionnel. La branche hors-ligne de B6 construit son propre `body` plus haut et n'est PAS modifiée — une vente hors-ligne reste anonyme.)

Dans le bloc de succès de `processSale()` (chemin en ligne), après `cart = []`, réinitialiser le client sélectionné :
```javascript
    cart = [];
    discount = { type: null, value: 0 };
    pendingSaleId = null;
    selectedCustomer = null;
    renderCustomerField();
    renderCart();
```
(Ajouter les deux lignes `selectedCustomer = null;` et `renderCustomerField();` juste après la réinitialisation de `discount`/`pendingSaleId` existante.)

- [ ] **Step 4: Vérification statique**

1. `node --check` sur le JS inline extrait de `pos.html`.
2. `grep -n "selectedCustomer" frontend/pos.html` → déclaration + usages (searchCustomers/createCustomerInline/processSale/renderCustomerField).
3. Confirmer que la branche hors-ligne de `processSale()` (celle qui teste `!navigator.onLine`) n'a PAS été modifiée (git diff).

- [ ] **Step 5: Vérification manuelle**

1. Serveurs démarrés, POS en ligne, caisse ouverte.
2. Taper un nom dans « Client (optionnel) » → la recherche propose les clients ; en sélectionner un → il s'affiche sur le panier avec une croix.
3. Cliquer le « + » → saisir nom + téléphone → le client est créé et rattaché automatiquement.
4. Ajouter un produit, encaisser → le reçu peut montrer le client ; vérifier sur la page Clients que la vente apparaît dans la fiche du client (total dépensé mis à jour).
5. Faire une vente **sans** sélectionner de client → vente anonyme, aucun changement de comportement.
6. Retirer le client via la croix avant d'encaisser → la vente redevient anonyme.

- [ ] **Step 6: Commit**

```bash
git add frontend/pos.html
git commit -m "feat: selecteur client optionnel a la caisse (recherche, creation a la volee, rattachement)"
```

---

### Task 5: Vérification finale du module

**Files:** aucun changement de code — vérification uniquement.

- [ ] **Step 1: Suite complète**

Run: `cd backend && php artisan test` — Attendu : tous PASS (102 tests, sortie propre).

- [ ] **Step 2: Non-régression vente anonyme**

Sur `pos.html`, faire une vente espèces normale sans client : fonctionne comme avant. Confirmer via `git diff master -- backend/app/Http/Controllers/Api/SaleController.php` que `store()` n'a reçu que des ajouts (règle `customer_id`, champ à la création, clé `customer` dans `formatSale`, `customer:id,name` dans le `->load`), aucune ligne existante supprimée.

- [ ] **Step 3: Vérification croisée bout en bout**

Créer un client, faire deux ventes rattachées à ce client, ouvrir sa fiche : les deux ventes apparaissent, le total dépensé = somme des deux. Tenter de supprimer ce client → refusé (a des ventes). Créer un client sans vente → suppression OK.

- [ ] **Step 4: Commit (si correctif)**

Sinon, rien à commit — cette tâche clôt le module avant la revue finale de branche.
