# Phase B1 — Reçus imprimables + Remboursement POS — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reçus imprimables (PDF dompdf + impression ticket 80 mm via le navigateur, avec nom du caissier) et câblage UI du remboursement POS sur l'endpoint existant `POST /sales/{id}/refund`, le tout couvert par des tests automatisés backend.

**Architecture:** Backend Laravel 12 : un nouvel endpoint `GET /api/sales/{sale}/receipt-pdf` (méthode dans `SaleController`, vue Blade `receipts/sale`, dompdf déjà installé) + enrichissement de `formatSale()` avec `refunded_quantity` par article. Frontend : le reçu à l'écran de `pos.html` gagne le nom du caissier, un bouton Imprimer (CSS `@media print` 80 mm) et un bouton PDF (fetch blob authentifié) ; nouvelle modal Remboursement (recherche par n° de reçu → quantités → motif → POST). Tests : PHPUnit/`artisan test`, SQLite `:memory:` (déjà configuré dans `phpunit.xml`), trait d'aide `CreatesShopData` qui passe par les vraies routes API.

**Tech Stack:** Laravel 12, Sanctum, spatie/laravel-permission (rôles guard `web`), barryvdh/laravel-dompdf ^3.1 (installé, jamais utilisé), PHPUnit via `php artisan test`, frontend vanilla JS existant (`api.js`, `app.js`).

## Global Constraints

- Phase B, spec `docs/superpowers/specs/2026-07-24-refonte-globale-design.md` : un module à la fois, branche dédiée, livré **avec ses tests automatisés**, aucun changement visuel hors composants nécessaires au module (réutiliser le design system Phase A : `.btn`, `.modal-backdrop`, `.form-control`, `renderTable`, `toast()`, icônes Lucide existantes).
- Branche de travail : `feat/b1-recus-remboursement`, créée depuis `master`. Un commit par tâche. Ne pas pousser vers origin sans demande du client.
- Tests : `cd backend && php artisan test` doit passer en entier à la fin de chaque tâche (les 2 tests Example existants inclus).
- Montants : entiers FCFA partout (pas de décimales) — convention existante.
- Rôles : `proprietaire`, `gestionnaire`, `caissier`, `vendeur` (guard `web`, `Role::firstOrCreate`).
- Réponses API : enveloppe `{success, message, data}` via les helpers `success()/error()` du contrôleur — `api.js` déballe `json.data`.
- Le seuil de remboursement est lu via `Setting::getValue('remboursement_max', 50000)` (modèle existant, cache 300 s — utiliser `Setting::setValue()` dans les tests pour invalider le cache).
- Ne PAS toucher : `frontend/css/app.css` (sauf ajout du bloc `@media print` en fin de fichier), la logique de vente existante de `pos.html` (`processSale`, panier, session).

## File Structure

- **Modify:** `frontend/js/api.js` — ajout du header `Accept: application/json` (Task 1).
- **Create:** `backend/tests/Support/CreatesShopData.php` — trait d'aide de test (Task 2).
- **Create:** `backend/tests/Feature/SaleFlowTest.php` — smoke test du flux de vente (Task 2).
- **Create:** `backend/resources/views/receipts/sale.blade.php` — vue Blade du reçu 80 mm (Task 3).
- **Modify:** `backend/app/Http/Controllers/Api/SaleController.php` — méthode `receiptPdf()` + `refunded_quantity` dans `formatSale()` (Tasks 3-4).
- **Modify:** `backend/routes/api.php` — route `GET sales/{sale}/receipt-pdf` (Task 3).
- **Create:** `backend/tests/Feature/ReceiptPdfTest.php` (Task 3).
- **Create:** `backend/tests/Feature/RefundTest.php` (Task 4).
- **Modify:** `frontend/pos.html` — reçu enrichi + impression + PDF (Task 5) ; modal Remboursement (Task 6).

---

### Task 1: `api.js` — header `Accept: application/json`

Corrige le finding de la Phase A : avec un token expiré, Laravel renvoie 500 « Route [login] not defined » au lieu de 401, parce que la requête n'annonce pas `Accept: application/json` (le middleware `Authenticate` tente alors une redirection web vers une route `login` inexistante). Avec le header, Laravel renvoie 401 JSON et le handler existant de `api.js` (lignes 29-33) déconnecte proprement.

**Files:**
- Modify: `frontend/js/api.js:7-8`

**Interfaces:**
- Consumes: rien.
- Produces: tout appel `api.*` envoie désormais `Accept: application/json` — comportement 401 propre dont dépendent toutes les pages.

- [ ] **Step 1: Modifier `_fetch`**

Remplacer :
```javascript
    const headers = { Authorization: `Bearer ${this._token()}` };
    if (!isForm) headers['Content-Type'] = 'application/json';
```
par :
```javascript
    const headers = { Authorization: `Bearer ${this._token()}`, Accept: 'application/json' };
    if (!isForm) headers['Content-Type'] = 'application/json';
```

- [ ] **Step 2: Vérifier**

Backend démarré (`cd backend && php artisan serve --port=8000`) :
```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/api/dashboard -H "Authorization: Bearer token-invalide" -H "Accept: application/json"
```
Attendu : `401` (et plus 500). Contre-essai sans le header `Accept` : `500` (comportement d'avant, prouve que le header est bien la cause).

- [ ] **Step 3: Commit**

```bash
git add frontend/js/api.js
git commit -m "fix: header Accept json dans api.js — 401 propre au lieu de 500 sur token expire"
```

---

### Task 2: Infrastructure de test — trait `CreatesShopData` + smoke test

Il n'existe aucun test métier. `phpunit.xml` est déjà configuré en SQLite `:memory:` ; la migration `2026_06_29_200000_create_boutique_tables.php` n'utilise que des types portables (`enum` Laravel émulé) — compatible SQLite. Il n'y a pas de factories pour les modèles boutique (et ils n'ont pas le trait `HasFactory`) : le trait d'aide crée les données via Eloquent et **passe par la vraie route `POST /api/sales`** pour créer une vente (exercice réel du flux, pas de dépendance aux `$fillable`).

**Files:**
- Create: `backend/tests/Support/CreatesShopData.php`
- Create: `backend/tests/Feature/SaleFlowTest.php`

**Interfaces:**
- Consumes: modèles existants (`User`, `Category`, `Product`, `ProductPrice`, `Stock`, `CashSession`), routes API existantes.
- Produces (utilisés par Tasks 3-4) :
  - `$this->makeUser(string $role, string $username = null): User` — crée un utilisateur actif avec le rôle spatie donné.
  - `$this->makeProduct(int $retail = 500, int $purchase = 300, int $stockQty = 100): Product` — produit actif avec prix et stock.
  - `$this->openSession(User $cashier): CashSession` — session de caisse ouverte.
  - `$this->makeSaleViaApi(User $cashier, Product $product, int $qty = 2): array` — POST /api/sales (espèces, détail), retourne le `data` de la réponse (avec `id`, `receipt_number`, `items[*].id`...). Ouvre une session si le caissier n'en a pas.

- [ ] **Step 1: Écrire le trait**

`backend/tests/Support/CreatesShopData.php` :
```php
<?php

namespace Tests\Support;

use App\Models\CashSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Stock;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

trait CreatesShopData
{
    protected function seedRoles(): void
    {
        foreach (['proprietaire', 'gestionnaire', 'caissier', 'vendeur'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    protected function makeUser(string $role, ?string $username = null): User
    {
        $this->seedRoles();
        $user = User::create([
            'name'      => ucfirst($role) . ' Test',
            'username'  => $username ?? $role . '_' . uniqid(),
            'password'  => 'secret123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function makeProduct(int $retail = 500, int $purchase = 300, int $stockQty = 100): Product
    {
        $category = Category::firstOrCreate(['name' => 'Test Cat']);
        $product  = Product::create([
            'category_id'     => $category->id,
            'name'            => 'Produit ' . uniqid(),
            'unit'            => 'piece',
            'min_stock_alert' => 5,
            'is_active'       => true,
        ]);
        ProductPrice::create([
            'product_id'        => $product->id,
            'purchase_price'    => $purchase,
            'retail_price'      => $retail,
            'wholesale_price'   => $retail - 100,
            'wholesale_min_qty' => 12,
        ]);
        Stock::create(['product_id' => $product->id, 'quantity' => $stockQty]);

        return $product;
    }

    protected function openSession(User $cashier): CashSession
    {
        return CashSession::create([
            'cashier_id'     => $cashier->id,
            'opening_amount' => 10000,
            'opened_at'      => now(),
        ]);
    }

    protected function makeSaleViaApi(User $cashier, Product $product, int $qty = 2): array
    {
        if (!CashSession::where('cashier_id', $cashier->id)->whereNull('closed_at')->exists()) {
            $this->openSession($cashier);
        }
        Sanctum::actingAs($cashier);

        $retail = $product->price->retail_price;
        $response = $this->postJson('/api/sales', [
            'sale_type'      => 'detail',
            'payment_method' => 'especes',
            'items'          => [['product_id' => $product->id, 'quantity' => $qty]],
            'amount_paid'    => $retail * $qty,
        ]);
        $response->assertCreated();

        return $response->json('data');
    }
}
```

Note : si `Product` n'a pas de relation `price` (vérifier `app/Models/Product.php` — la relation existe, elle est utilisée par `ProductController`), adapter le nom exact trouvé dans le modèle. Si `POST /api/sales` attend d'autres champs obligatoires (lire `SaleController::store()` lignes 19-60 avant d'écrire le trait), utiliser exactement les champs de sa validation — le smoke test échouera sinon, c'est le but du smoke test.

- [ ] **Step 2: Écrire le smoke test**

`backend/tests/Feature/SaleFlowTest.php` :
```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class SaleFlowTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_une_vente_complete_se_cree_via_l_api(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct(retail: 500, stockQty: 50);

        $sale = $this->makeSaleViaApi($cashier, $product, qty: 3);

        $this->assertSame(1500, $sale['total']);
        $this->assertStringStartsWith('VTE-', $sale['receipt_number']);
        $this->assertSame($cashier->name, $sale['cashier']);
        $this->assertCount(1, $sale['items']);
        $this->assertSame(47, $product->stock->fresh()->quantity);
    }
}
```

- [ ] **Step 3: Lancer et faire passer**

Run: `cd backend && php artisan test --filter=SaleFlowTest`
Attendu : PASS (ou itérer : le premier échec révélera les champs exacts attendus par `store()` — lire la validation et ajuster le trait, pas le contrôleur).

- [ ] **Step 4: Suite complète**

Run: `php artisan test` — Attendu : tous PASS (Example inclus).

- [ ] **Step 5: Commit**

```bash
git add tests/
git commit -m "test: infrastructure de test metier (trait CreatesShopData + smoke test vente)"
```

---

### Task 3: Endpoint PDF du reçu (TDD)

**Files:**
- Create: `backend/resources/views/receipts/sale.blade.php`
- Modify: `backend/app/Http/Controllers/Api/SaleController.php` (ajout méthode `receiptPdf` + import)
- Modify: `backend/routes/api.php:44` (dans le groupe `sales`)
- Test: `backend/tests/Feature/ReceiptPdfTest.php`

**Interfaces:**
- Consumes: `CreatesShopData` (Task 2), `formatSale()` existant.
- Produces: `GET /api/sales/{sale}/receipt-pdf` → réponse `application/pdf` en flux (`Pdf::stream`), nom de fichier `recu-<receipt_number>.pdf`. La vue Blade `receipts.sale` reçoit `['sale' => <tableau formatSale>]`.

- [ ] **Step 1: Écrire les tests qui échouent**

`backend/tests/Feature/ReceiptPdfTest.php` :
```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class ReceiptPdfTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_le_pdf_du_recu_est_genere(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct();
        $sale    = $this->makeSaleViaApi($cashier, $product);

        Sanctum::actingAs($cashier);
        $response = $this->get("/api/sales/{$sale['id']}/receipt-pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_la_vue_du_recu_contient_caissier_et_numero(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct();
        $sale    = $this->makeSaleViaApi($cashier, $product);

        $html = view('receipts.sale', ['sale' => $sale])->render();

        $this->assertStringContainsString($sale['receipt_number'], $html);
        $this->assertStringContainsString($cashier->name, $html);
        $this->assertStringContainsString('Boutique D', $html);
    }

    public function test_le_pdf_exige_une_authentification(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct();
        $sale    = $this->makeSaleViaApi($cashier, $product);

        $this->app['auth']->forgetGuards();

        $this->getJson("/api/sales/{$sale['id']}/receipt-pdf")->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `php artisan test --filter=ReceiptPdfTest`
Attendu : FAIL — 404 (route inexistante) et « View [receipts.sale] not found ».

- [ ] **Step 3: Implémenter**

1. Route dans `backend/routes/api.php`, groupe `sales`, juste après `Route::get('{sale}', ...)` :
```php
        Route::get('{sale}/receipt-pdf', [SaleController::class, 'receiptPdf']);
```

2. Import + méthode dans `SaleController` (après `refund()`, avant les helpers) :
```php
use Barryvdh\DomPDF\Facade\Pdf;
```
```php
    public function receiptPdf(Sale $sale)
    {
        $sale->load(['items.product', 'cashier:id,name']);

        return Pdf::loadView('receipts.sale', ['sale' => $this->formatSale($sale)])
            ->setPaper([0, 0, 226.77, 841.89]) // 80 mm de large
            ->stream('recu-' . $sale->receipt_number . '.pdf');
    }
```

3. Vue `backend/resources/views/receipts/sale.blade.php` (DejaVu Sans : seule famille avec accents fiable sous dompdf) :
```blade
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #111; padding: 8px 10px; }
  h1 { font-size: 13px; text-align: center; }
  .sub { text-align: center; color: #444; margin-top: 2px; }
  hr { border: none; border-top: 1px dashed #999; margin: 6px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 2px 0; vertical-align: top; }
  td.r { text-align: right; white-space: nowrap; }
  .total td { font-weight: bold; font-size: 11px; padding-top: 4px; }
  .footer { text-align: center; margin-top: 10px; color: #555; }
</style>
</head>
<body>
  <h1>Boutique D</h1>
  <div class="sub">{{ $sale['receipt_number'] }}</div>
  <div class="sub">{{ $sale['date'] }}</div>
  <div class="sub">Caissier : {{ $sale['cashier'] ?? '—' }}</div>
  <hr>
  <table>
    @foreach ($sale['items'] as $item)
    <tr>
      <td>{{ $item['product'] }} × {{ $item['quantity'] }}</td>
      <td class="r">{{ number_format($item['total'], 0, ',', ' ') }} F</td>
    </tr>
    @endforeach
  </table>
  <hr>
  <table>
    <tr><td>Sous-total</td><td class="r">{{ number_format($sale['subtotal'], 0, ',', ' ') }} F</td></tr>
    @if (($sale['discount_value'] ?? 0) > 0)
    <tr><td>Remise</td><td class="r">- {{ number_format($sale['discount_value'], 0, ',', ' ') }}{{ $sale['discount_type'] === 'percent' ? ' %' : ' F' }}</td></tr>
    @endif
    <tr class="total"><td>TOTAL</td><td class="r">{{ number_format($sale['total'], 0, ',', ' ') }} FCFA</td></tr>
    <tr><td>Paiement</td><td class="r">{{ $sale['payment_method'] === 'especes' ? 'Espèces' : 'Mobile Money' }}</td></tr>
    @if (($sale['amount_paid'] ?? 0) > 0)
    <tr><td>Montant reçu</td><td class="r">{{ number_format($sale['amount_paid'], 0, ',', ' ') }} F</td></tr>
    @endif
    @if (($sale['change_given'] ?? 0) > 0)
    <tr><td>Monnaie rendue</td><td class="r">{{ number_format($sale['change_given'], 0, ',', ' ') }} F</td></tr>
    @endif
  </table>
  <hr>
  <div class="footer">Merci de votre visite !</div>
</body>
</html>
```

- [ ] **Step 4: Vérifier le passage**

Run: `php artisan test --filter=ReceiptPdfTest` — Attendu : 3 PASS.
Puis `php artisan test` — Attendu : tous PASS.

- [ ] **Step 5: Commit**

```bash
git add routes/api.php app/Http/Controllers/Api/SaleController.php resources/views/receipts/ tests/Feature/ReceiptPdfTest.php
git commit -m "feat: endpoint PDF du recu (dompdf, 80mm, nom du caissier)"
```

---

### Task 4: `refunded_quantity` dans `formatSale` + tests du remboursement (TDD)

L'endpoint `refund` existe mais n'a aucun test, et l'UI (Task 6) a besoin de connaître la quantité déjà remboursée par article pour plafonner la saisie.

**Files:**
- Modify: `backend/app/Http/Controllers/Api/SaleController.php` — `formatSale()` (~ligne 300)
- Test: `backend/tests/Feature/RefundTest.php`

**Interfaces:**
- Consumes: `CreatesShopData`, endpoint `POST /api/sales/{sale}/refund` existant (payload `{items: [{sale_item_id, quantity}], reason}`), `Setting::setValue('remboursement_max', N)`.
- Produces: chaque entrée de `items` dans `formatSale()` contient en plus `'refunded_quantity' => int` (0 si aucun remboursement) — consommé par la modal de la Task 6 via `GET /sales/receipt`.

- [ ] **Step 1: Écrire les tests qui échouent**

`backend/tests/Feature/RefundTest.php` :
```php
<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_remboursement_partiel_reintegre_le_stock(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct(retail: 500, stockQty: 50);
        $sale    = $this->makeSaleViaApi($cashier, $product, qty: 3); // stock: 47

        Sanctum::actingAs($cashier);
        $response = $this->postJson("/api/sales/{$sale['id']}/refund", [
            'items'  => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
            'reason' => 'Produit défectueux',
        ]);

        $response->assertOk();
        $this->assertSame(1000, $response->json('data.amount'));
        $this->assertSame(49, $product->stock->fresh()->quantity);
    }

    public function test_quantite_superieure_au_restant_rejetee(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct();
        $sale    = $this->makeSaleViaApi($cashier, $product, qty: 2);

        Sanctum::actingAs($cashier);
        $this->postJson("/api/sales/{$sale['id']}/refund", [
            'items'  => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 5]],
            'reason' => 'Erreur',
        ])->assertStatus(422);
    }

    public function test_seuil_depasse_refuse_pour_caissier(): void
    {
        Setting::setValue('remboursement_max', 100);
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct(retail: 500);
        $sale    = $this->makeSaleViaApi($cashier, $product, qty: 2);

        Sanctum::actingAs($cashier);
        $this->postJson("/api/sales/{$sale['id']}/refund", [
            'items'  => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
            'reason' => 'Retour client',
        ])->assertStatus(403);
    }

    public function test_le_seuil_ne_bloque_pas_le_proprietaire(): void
    {
        Setting::setValue('remboursement_max', 100);
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500);
        $sale    = $this->makeSaleViaApi($owner, $product, qty: 2);

        Sanctum::actingAs($owner);
        $this->postJson("/api/sales/{$sale['id']}/refund", [
            'items'  => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
            'reason' => 'Retour client',
        ])->assertOk();
    }

    public function test_find_by_receipt_expose_la_quantite_remboursee(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct();
        $sale    = $this->makeSaleViaApi($cashier, $product, qty: 3);

        Sanctum::actingAs($cashier);
        $this->postJson("/api/sales/{$sale['id']}/refund", [
            'items'  => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
            'reason' => 'Casse',
        ])->assertOk();

        $data = $this->getJson('/api/sales/receipt?receipt_number=' . $sale['receipt_number'])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['items'][0]['refunded_quantity']);
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `php artisan test --filter=RefundTest`
Attendu : les 4 premiers tests PASSENT (endpoint existant — c'est une couverture de l'existant) ; le 5e FAIL (`refunded_quantity` absent). Si l'un des 4 premiers échoue, c'est un vrai bug de l'endpoint : STOP, le documenter dans le rapport et remonter au contrôleur au lieu de « réparer » silencieusement.

- [ ] **Step 3: Implémenter `refunded_quantity`**

Dans `formatSale()` (`SaleController.php` ~ligne 300), avant le `return`, calculer les quantités remboursées, puis les injecter dans le map des items :
```php
    private function formatSale(Sale $sale): array
    {
        $refundedByProduct = \App\Models\RefundItem::whereHas('refund', fn($q) => $q->where('sale_id', $sale->id))
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        return [
            // ... champs existants inchangés ...
            'items' => $sale->relationLoaded('items')
                ? $sale->items->map(fn($i) => [
                    'id'                => $i->id,
                    'product'           => $i->product?->name,
                    'unit'              => $i->product?->unit,
                    'quantity'          => $i->quantity,
                    'unit_price'        => $i->unit_price,
                    'total'             => $i->total,
                    'refunded_quantity' => (int) ($refundedByProduct[$i->product_id] ?? 0),
                ])
                : [],
        ];
    }
```
(Ne changer QUE l'ajout de `$refundedByProduct` et de la clé `refunded_quantity` — tous les autres champs restent identiques.)

- [ ] **Step 4: Vérifier le passage**

Run: `php artisan test --filter=RefundTest` — Attendu : 5 PASS. Puis `php artisan test` — tous PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/SaleController.php tests/Feature/RefundTest.php
git commit -m "feat: refunded_quantity par article + couverture de tests du remboursement"
```

---

### Task 5: Reçu à l'écran — caissier, impression 80 mm, bouton PDF

**Files:**
- Modify: `frontend/pos.html` — fonction `showReceipt()` (~ligne 696) + CSS inline de la page

**Interfaces:**
- Consumes: `formatSale` (le POST /sales renvoie `date`, `cashier`, `id`), `GET /api/sales/{id}/receipt-pdf` (Task 3), `API_BASE` (constante globale de `api.js`), `toast()`.
- Produces: `openReceiptPdf(saleId)` (fonction inline pos.html) — réutilisable par la Task 6.

- [ ] **Step 1: Corriger la date et ajouter le caissier dans `showReceipt()`**

Dans le template du reçu (~ligne 705), remplacer :
```javascript
      <div class="receipt-sub">${escHtml(sale.created_at ?? '')}</div>
```
par :
```javascript
      <div class="receipt-sub">${escHtml(sale.date ?? '')}</div>
```
(bug pré-existant : `formatSale` renvoie `date`, pas `created_at` — la date du reçu était toujours vide) et ajouter juste sous la ligne du numéro de reçu (~ligne 706) :
```javascript
      <div class="receipt-sub">Caissier : ${escHtml(sale.cashier ?? '—')}</div>
```

- [ ] **Step 2: Boutons Imprimer + PDF**

À la fin du template de `showReceipt()` (après la `div.receipt`, dans le même innerHTML), ajouter :
```javascript
    <div class="flex gap-2 mt-4 no-print">
      <button class="btn w-full" onclick="window.print()"><i data-lucide="printer" class="icon"></i> Imprimer</button>
      <button class="btn w-full" onclick="openReceiptPdf(${Number(sale.id)})"><i data-lucide="file-down" class="icon"></i> PDF</button>
    </div>
```
et vérifier qu'un `refreshIcons()` suit l'injection (déjà présent dans `showReceipt`).

Ajouter la fonction dans le script inline de pos.html :
```javascript
async function openReceiptPdf(saleId) {
  try {
    const res = await fetch(`${API_BASE}/sales/${saleId}/receipt-pdf`, {
      headers: { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/pdf' },
    });
    if (!res.ok) throw new Error(`Erreur ${res.status}`);
    window.open(URL.createObjectURL(await res.blob()), '_blank');
  } catch (err) {
    toast(err.message, 'danger');
  }
}
```

- [ ] **Step 3: CSS d'impression 80 mm**

Dans le `<style>` inline de pos.html, à la fin :
```css
    @media print {
      body * { visibility: hidden !important; }
      .receipt, .receipt * { visibility: visible !important; }
      .receipt { position: absolute; left: 0; top: 0; width: 80mm; box-shadow: none; border: none; }
      .no-print { display: none !important; }
    }
```

- [ ] **Step 4: Vérification manuelle**

1. Serveurs démarrés, login `admin`/`admin123`, POS : ouvrir la caisse, faire une vente espèces.
2. Le reçu affiche : date non vide, « Caissier : Propriétaire », boutons Imprimer et PDF.
3. Imprimer → l'aperçu d'impression ne montre QUE le ticket (80 mm).
4. PDF → un onglet s'ouvre avec le PDF dompdf (mêmes infos, accents corrects).
5. `node --check` sur le JS inline extrait de pos.html.

- [ ] **Step 5: Commit**

```bash
git add frontend/pos.html
git commit -m "feat: recu a l'ecran avec caissier, impression 80mm et telechargement PDF"
```

---

### Task 6: Modal Remboursement (B1b)

**Files:**
- Modify: `frontend/pos.html` — bouton d'en-tête + modal + JS

**Interfaces:**
- Consumes: `GET /api/sales/receipt?receipt_number=...` (renvoie `formatSale` avec `refunded_quantity`, Task 4), `POST /api/sales/{id}/refund` (`{items: [{sale_item_id, quantity}], reason}`), helpers `openModal/closeModal/toast/escHtml/fmt/refreshIcons`, design system (`.modal-backdrop`, `.btn`, `.form-control`, `.badge`).
- Produces: rien (fonctionnalité terminale).

- [ ] **Step 1: Bouton d'ouverture**

Dans l'en-tête de page du POS (à côté des boutons existants de session), ajouter :
```html
<button class="btn" id="btn-refund" onclick="openRefundModal()"><i data-lucide="rotate-ccw" class="icon"></i> Remboursement</button>
```

- [ ] **Step 2: Markup de la modal**

Avant `</body>`, à côté des modals existantes, sur le modèle exact des autres modals de la page (`.modal-backdrop` > `.modal` > header/body/footer) :
```html
<div class="modal-backdrop" id="refund-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><i data-lucide="rotate-ccw" class="icon"></i> Remboursement</div>
      <button class="modal-close" data-dismiss><i data-lucide="x" class="icon"></i></button>
    </div>
    <div class="modal-body">
      <div class="flex gap-2">
        <input type="text" class="form-control" id="refund-receipt-input" placeholder="N° de reçu (VTE-...)">
        <button class="btn btn-primary" id="btn-refund-search"><i data-lucide="search" class="icon"></i></button>
      </div>
      <div id="refund-details" class="hidden">
        <div id="refund-sale-info" class="font-sm text-muted"></div>
        <div class="table-wrap mt-4">
          <table>
            <thead><tr><th>Article</th><th>Vendu</th><th>Remboursé</th><th>À rembourser</th></tr></thead>
            <tbody id="refund-items-tbody"></tbody>
          </table>
        </div>
        <div class="form-group mt-4">
          <label class="form-label" for="refund-reason">Motif *</label>
          <input type="text" class="form-control" id="refund-reason" maxlength="255" placeholder="Ex : produit défectueux">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" data-dismiss>Annuler</button>
      <button class="btn btn-danger" id="btn-refund-submit" disabled><i data-lucide="rotate-ccw" class="icon"></i> Rembourser</button>
    </div>
  </div>
</div>
```

- [ ] **Step 3: JS de la modal**

Dans le script inline de pos.html :
```javascript
let refundSale = null;

function openRefundModal() {
  refundSale = null;
  document.getElementById('refund-receipt-input').value = '';
  document.getElementById('refund-reason').value = '';
  document.getElementById('refund-details').classList.add('hidden');
  document.getElementById('btn-refund-submit').disabled = true;
  openModal('refund-modal');
}

async function searchRefundSale() {
  const num = document.getElementById('refund-receipt-input').value.trim();
  if (!num) return;
  try {
    refundSale = await api.get(`/sales/receipt?receipt_number=${encodeURIComponent(num)}`);
    document.getElementById('refund-sale-info').textContent =
      `${refundSale.receipt_number} — ${refundSale.date} — ${refundSale.cashier ?? '—'} — Total ${fmt(refundSale.total)}`;
    document.getElementById('refund-items-tbody').innerHTML = refundSale.items.map(i => {
      const restant = i.quantity - (i.refunded_quantity ?? 0);
      return `<tr>
        <td>${escHtml(i.product ?? '—')}</td>
        <td>${i.quantity}</td>
        <td>${i.refunded_quantity ?? 0}</td>
        <td>${restant > 0
          ? `<input type="number" class="form-control refund-qty" data-item-id="${i.id}" min="0" max="${restant}" value="0" style="width:80px">`
          : '<span class="badge badge-neutral">Épuisé</span>'}</td>
      </tr>`;
    }).join('');
    document.getElementById('refund-details').classList.remove('hidden');
    document.getElementById('btn-refund-submit').disabled = false;
    refreshIcons();
  } catch (err) {
    toast(err.message, 'danger');
  }
}

async function submitRefund() {
  if (!refundSale) return;
  const items = [...document.querySelectorAll('.refund-qty')]
    .map(inp => ({ sale_item_id: Number(inp.dataset.itemId), quantity: Number(inp.value) }))
    .filter(i => i.quantity > 0);
  const reason = document.getElementById('refund-reason').value.trim();
  if (items.length === 0) { toast('Saisissez au moins une quantité à rembourser.', 'warning'); return; }
  if (!reason) { toast('Le motif est obligatoire.', 'warning'); return; }

  const btn = document.getElementById('btn-refund-submit');
  btn.disabled = true;
  try {
    const result = await api.post(`/sales/${refundSale.id}/refund`, { items, reason });
    toast(`Remboursement de ${fmt(result.amount)} effectué. Stock réintégré.`, 'success');
    await searchRefundSale(); // rafraîchit les quantités remboursées
    loadProducts();           // rafraîchit le stock affiché dans la grille
  } catch (err) {
    toast(err.message, 'danger');
  } finally {
    btn.disabled = false;
  }
}

document.getElementById('btn-refund-search').addEventListener('click', searchRefundSale);
document.getElementById('refund-receipt-input').addEventListener('keydown', e => { if (e.key === 'Enter') searchRefundSale(); });
document.getElementById('btn-refund-submit').addEventListener('click', submitRefund);
```

- [ ] **Step 4: Vérification manuelle**

1. Faire une vente de 3 unités, noter le n° de reçu.
2. Remboursement → rechercher le reçu → la ligne montre Vendu 3 / Remboursé 0 / input max 3.
3. Rembourser 1 avec motif → toast succès, la modal se rafraîchit (Remboursé 1, max 2), le stock de la grille produits remonte de 1.
4. Tenter 5 → le champ est plafonné à max (et le backend renverrait 422).
5. En tant que `caissier` (jean/[mot de passe seedé]) avec un montant > seuil → message 403 explicite du backend affiché en toast.
6. `node --check` sur le JS inline extrait.

- [ ] **Step 5: Commit**

```bash
git add frontend/pos.html
git commit -m "feat: remboursement POS — recherche par recu, quantites, motif (B1b)"
```
