# Phase B5 — Vente initiée par un vendeur sur tablette — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre à un vendeur de constituer un panier sur une page dédiée (tablette), l'envoyer à la caisse (stock réservé immédiatement), puis à un caissier de le retrouver, l'ajuster si besoin et l'encaisser — ou de l'annuler (stock restitué).

**Architecture:** Backend : la table `sales` gagne une colonne `status` (`en_attente`/`validee`/`annulee`, défaut `validee` — aucune vente existante n'est affectée) ; `cashier_id` et `payment_method` deviennent nullables (une vente en attente n'a ni caissier ni mode de paiement tant qu'elle n'est pas validée). Trois nouvelles actions sur `SaleController` : `storePending()` (création par le vendeur, calcul et vérifications dupliqués de `store()` à l'identique pour ne pas toucher au chemin de vente critique déjà testé), `pending()`/`validatePending()`/`cancelPending()` (côté caisse). Frontend : une nouvelle page `vente-tablette.html` (grille produits + panier, sans étape de paiement) et, dans `pos.html`, un bouton « Ventes en attente » ouvrant une modal listant les paniers à traiter — « Charger » les injecte dans le panier de caisse existant (réutilise 100% du flux d'encaissement déjà en place), « Annuler » les rejette.

**Tech Stack:** Laravel 12 (migration de colonnes sans `doctrine/dbal`, natif depuis Laravel 11), PHPUnit (`php artisan test`), frontend vanilla JS existant (`api.js`, `app.js`, design system Phase A).

## Global Constraints

- Cahier des charges, module 4.3 : vente initiée par un vendeur sur tablette, validée à la caisse.
- Décisions de cadrage validées le 2026-07-24 : (1) nouvelle page dédiée `vente-tablette.html` plutôt que réutiliser `pos.html` ; (2) le stock est décompté dès l'envoi du panier par le vendeur (pas à la validation) ; (3) le caissier peut modifier le panier avant d'encaisser.
- **Ne PAS refactorer `SaleController::store()`** — la nouvelle méthode `storePending()` duplique le calcul de prix/stock/remise nécessaire (~35 lignes identiques) plutôt que d'extraire un helper partagé, pour ne courir aucun risque de régression sur le chemin de vente déjà testé et utilisé en production.
- Le statut d'une vente normale (créée via `POST /sales` existant) reste implicitement `validee` (valeur par défaut de la colonne) — comportement inchangé, zéro migration de données nécessaire au-delà du défaut de colonne.
- Traçabilité : `activity_log()` à chaque étape (`vente_en_attente`, `validation_vente_attente`, `annulation_vente_attente`), une seule entrée par appel (convention déjà établie en B3/B4).
- Réutiliser `tests/Support/CreatesShopData.php` (déjà fournit `makeUser`, `makeProduct`, `openSession`) — pas de nouvelle méthode requise dans le trait.
- Branche de travail : `feat/b5-vente-tablette`, créée depuis `master`. Un commit par tâche. Ne pas pousser vers origin sans demande du client.
- Tests : `cd backend && php artisan test` doit passer en entier (36 tests existants + les nouveaux) à la fin de chaque tâche.
- Montants entiers FCFA, conventions de réponse (`{success,message,data}`) et de validation identiques au reste de l'application.
- Ne PAS toucher : `SaleController::store()`, `refund()`, `show()`, `findByReceipt()`, `receiptPdf()` au-delà de l'extension additive de `formatSale()` (Task 2) ; `frontend/pos.html` au-delà des ajouts décrits en Task 4 (panier, remise, encaissement classique inchangés).

## File Structure

- **Create:** `backend/database/migrations/2026_07_24_090000_add_pending_status_to_sales_table.php`
- **Modify:** `backend/app/Models/Sale.php` — ajout de `'status'` au `$fillable`.
- **Modify:** `backend/app/Http/Controllers/Api/SaleController.php` — `storePending()`, `pending()`, `validatePending()`, `cancelPending()`, extension de `formatSale()`.
- **Modify:** `backend/routes/api.php` — 4 nouvelles routes dans le groupe `sales`.
- **Create:** `backend/tests/Feature/PendingSaleTest.php`, `backend/tests/Feature/PendingSaleValidationTest.php`.
- **Create:** `frontend/vente-tablette.html`.
- **Modify:** `frontend/dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html`, `profile.html`, `settings.html` — lien de navigation « Vente (tablette) » (9 fichiers).
- **Modify:** `frontend/pos.html` — bouton + modal « Ventes en attente », intégration dans `processSale()`.

---

### Task 1: Migration + `POST /sales/pending` (TDD)

**Files:**
- Create: `backend/database/migrations/2026_07_24_090000_add_pending_status_to_sales_table.php`
- Modify: `backend/app/Models/Sale.php`
- Modify: `backend/app/Http/Controllers/Api/SaleController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/PendingSaleTest.php`

**Interfaces:**
- Consumes: `tests/Support/CreatesShopData.php` (`makeUser`, `makeProduct`).
- Produces : `POST /api/sales/pending` — payload `{items: [{product_id, quantity}], sale_type: 'detail'|'gros', discount_type?, discount_value?, notes?}` → `201` `{success, message, data}` où `data` a la forme de `formatSale()` étendue (Task 2 en documente la forme finale ; pour cette tâche : `status: 'en_attente'`, `cashier: null`, `vendor: <nom>`). Consommé par les Tasks 3-4 (frontend) et par les tests de la Task 2.

- [ ] **Step 1: Écrire les tests qui échouent**

Créer `backend/tests/Feature/PendingSaleTest.php` :
```php
<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class PendingSaleTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_un_vendeur_peut_creer_un_panier_en_attente(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 50);

        Sanctum::actingAs($vendeur);
        $response = $this->postJson('/api/sales/pending', [
            'items'     => [['product_id' => $product->id, 'quantity' => 3]],
            'sale_type' => 'detail',
        ]);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertSame('en_attente', $data['status']);
        $this->assertSame(1500, $data['total']);
        $this->assertNull($data['cashier']);
        $this->assertSame($vendeur->name, $data['vendor']);
        $this->assertSame(47, $product->stock->fresh()->quantity, 'le stock doit etre reserve des la creation');
    }

    public function test_stock_insuffisant_rejette_la_creation(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(stockQty: 2);

        Sanctum::actingAs($vendeur);
        $this->postJson('/api/sales/pending', [
            'items'     => [['product_id' => $product->id, 'quantity' => 5]],
            'sale_type' => 'detail',
        ])->assertStatus(422);

        $this->assertSame(2, $product->stock->fresh()->quantity);
    }

    public function test_remise_excessive_refusee_pour_un_vendeur(): void
    {
        Setting::setValue('remise_max_sans_auth', 10);
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 1000, stockQty: 10);

        Sanctum::actingAs($vendeur);
        $this->postJson('/api/sales/pending', [
            'items'          => [['product_id' => $product->id, 'quantity' => 1]],
            'sale_type'      => 'detail',
            'discount_type'  => 'percent',
            'discount_value' => 50,
        ])->assertStatus(403);
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `cd backend && php artisan test --filter=PendingSaleTest`
Attendu : FAIL — 404 (route inexistante).

- [ ] **Step 3: Migration**

`backend/database/migrations/2026_07_24_090000_add_pending_status_to_sales_table.php` :
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
            $table->enum('status', ['en_attente', 'validee', 'annulee'])
                ->default('validee')
                ->after('vendor_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cashier_id')->nullable()->change();
            $table->enum('payment_method', ['especes', 'mobile_money'])->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cashier_id')->nullable(false)->change();
            $table->enum('payment_method', ['especes', 'mobile_money'])->default('especes')->nullable(false)->change();
            $table->dropColumn('status');
        });
    }
};
```
(La colonne `status` est ajoutée dans un premier `Schema::table` séparé du `change()` des colonnes existantes — évite tout conflit d'ordre d'exécution sur certains moteurs. `->nullable()->change()` est natif depuis Laravel 11, aucune dépendance `doctrine/dbal` requise.)

- [ ] **Step 4: Modèle `Sale`**

Dans `backend/app/Models/Sale.php`, ajouter `'status'` au tableau `$fillable` :
```php
    protected $fillable = [
        'receipt_number', 'cashier_id', 'vendor_id', 'cash_session_id', 'status',
        'sale_type', 'payment_method', 'mobile_money_number',
        'subtotal', 'discount_type', 'discount_value', 'total',
        'amount_paid', 'change_given', 'notes',
    ];
```

- [ ] **Step 5: Route**

Dans `backend/routes/api.php`, groupe `sales`, ajouter la route AVANT `Route::get('{sale}', ...)` (route littérale avant route à paramètre, même règle que `receipt`) :
```php
        Route::post('pending', [SaleController::class, 'storePending']);
```

- [ ] **Step 6: `storePending()`**

Dans `SaleController.php`, ajouter après `store()` (avant `index()`) :
```php
    public function storePending(Request $request): JsonResponse
    {
        $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1',
            'sale_type'              => 'required|in:detail,gros',
            'discount_type'          => 'nullable|in:percent,fixed',
            'discount_value'         => 'nullable|integer|min:0',
            'notes'                  => 'nullable|string',
        ]);

        // Charger les produits avec prix et stock
        $productIds = collect($request->items)->pluck('product_id');
        $products   = Product::with(['price', 'stock'])->whereIn('id', $productIds)->get()->keyBy('id');

        $subtotal  = 0;
        $lineItems = [];

        foreach ($request->items as $item) {
            $product = $products->get($item['product_id']);

            if (!$product || !$product->is_active) {
                return $this->error("Produit ID {$item['product_id']} indisponible.", 422);
            }

            $stockQty = $product->stock?->quantity ?? 0;
            if ($stockQty < $item['quantity']) {
                return $this->error(
                    "Stock insuffisant pour \"{$product->name}\" : {$stockQty} disponible(s), {$item['quantity']} demandé(s).",
                    422
                );
            }

            $price = $product->price;
            if ($request->sale_type === 'gros') {
                if ($item['quantity'] < $price->wholesale_min_qty) {
                    return $this->error(
                        "\"{$product->name}\" nécessite min. {$price->wholesale_min_qty} unité(s) pour le prix gros.",
                        422
                    );
                }
                $unitPrice = $price->wholesale_price;
            } else {
                $unitPrice = $price->retail_price;
            }

            $lineTotal  = $unitPrice * $item['quantity'];
            $subtotal  += $lineTotal;

            $lineItems[] = [
                'product'    => $product,
                'quantity'   => $item['quantity'],
                'unit_price' => $unitPrice,
                'total'      => $lineTotal,
            ];
        }

        $discountAmount = 0;
        if ($request->discount_type && $request->discount_value > 0) {
            $discountAmount = $request->discount_type === 'percent'
                ? (int) round($subtotal * $request->discount_value / 100)
                : $request->discount_value;

            $seuilPct = (int) Setting::getValue('remise_max_sans_auth', 10);
            $discountPct = ($subtotal > 0) ? ($discountAmount / $subtotal * 100) : 0;

            if ($discountPct > $seuilPct && !$request->user()->hasRole('proprietaire')) {
                return $this->error(
                    "Remise de " . round($discountPct, 1) . "% dépasse le seuil autorisé ({$seuilPct}%). Autorisation du propriétaire requise.",
                    403
                );
            }
        }

        $total = max(0, $subtotal - $discountAmount);

        $sale = DB::transaction(function () use ($request, $lineItems, $subtotal, $discountAmount, $total) {
            $sale = Sale::create([
                'receipt_number'  => $this->generateReceiptNumber(),
                'cashier_id'      => null,
                'vendor_id'       => $request->user()->id,
                'cash_session_id' => null,
                'status'          => 'en_attente',
                'sale_type'       => $request->sale_type,
                'payment_method'  => null,
                'subtotal'        => $subtotal,
                'discount_type'   => $request->discount_type,
                'discount_value'  => $request->discount_value ?? 0,
                'total'           => $total,
                'amount_paid'     => 0,
                'change_given'    => 0,
                'notes'           => $request->notes,
            ]);

            foreach ($lineItems as $line) {
                SaleItem::create([
                    'sale_id'    => $sale->id,
                    'product_id' => $line['product']->id,
                    'quantity'   => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total'      => $line['total'],
                ]);

                $line['product']->stock->decrement('quantity', $line['quantity']);
            }

            return $sale;
        });

        activity_log($request->user()->id, 'vente_en_attente', 'Sale', $sale->id, [
            'total'          => $total,
            'receipt_number' => $sale->receipt_number,
        ]);

        return $this->success(
            $this->formatSale($sale->load(['items.product', 'vendor:id,name'])),
            'Panier envoyé à la caisse.',
            201
        );
    }
```

- [ ] **Step 7: Extension additive de `formatSale()`**

Dans `SaleController.php`, méthode `formatSale()` (chercher `private function formatSale`), ajouter la clé `'status'` dans le tableau retourné et `'product_id'` dans le map des items. Remplacer :
```php
        return [
            'id'             => $sale->id,
            'receipt_number' => $sale->receipt_number,
            'sale_type'      => $sale->sale_type,
```
par :
```php
        return [
            'id'             => $sale->id,
            'receipt_number' => $sale->receipt_number,
            'status'         => $sale->status,
            'sale_type'      => $sale->sale_type,
```
et remplacer :
```php
                ? $sale->items->map(fn($i) => [
                    'id'                => $i->id,
                    'product'           => $i->product?->name,
```
par :
```php
                ? $sale->items->map(fn($i) => [
                    'id'                => $i->id,
                    'product_id'        => $i->product_id,
                    'product'           => $i->product?->name,
```

- [ ] **Step 8: Vérifier le passage**

Run: `php artisan test --filter=PendingSaleTest` — Attendu : 3 PASS.
Run: `php artisan test` — Attendu : tous PASS (39 tests). Si un test existant échoue à cause de la migration (ex. un test qui créait une vente sans passer par `store()`/`storePending()` et supposait `status` absent), corriger UNIQUEMENT ce test pour tenir compte du nouveau défaut `'validee'` — ne jamais modifier `SaleController::store()`.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_24_090000_add_pending_status_to_sales_table.php app/Models/Sale.php app/Http/Controllers/Api/SaleController.php routes/api.php tests/Feature/PendingSaleTest.php
git commit -m "feat: creation de panier en attente par un vendeur (POST /sales/pending)"
```

---

### Task 2: `GET /sales/pending`, `POST /sales/{sale}/validate`, `POST /sales/{sale}/cancel-pending` (TDD)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/SaleController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/PendingSaleValidationTest.php`

**Interfaces:**
- Consumes: `storePending()` (Task 1), `tests/Support/CreatesShopData.php`.
- Produces :
  - `GET /api/sales/pending` → `{data: [<formatSale sorted oldest first>, ...]}`.
  - `POST /api/sales/{sale}/validate` — payload `{items?: [{product_id, quantity}], sale_type?, payment_method (required), amount_paid (required), mobile_money_number?, discount_type?, discount_value?, notes?}` → `{data: <formatSale, status: 'validee'>}`. Si `items` absent, les articles du panier restent inchangés (stock déjà décompté à l'envoi). Si présent, remplace intégralement les articles (restitution puis re-décompte du stock).
  - `POST /api/sales/{sale}/cancel-pending` → `{message: '...'}`, restitue le stock, `status` passe à `annulee`.

- [ ] **Step 1: Écrire les tests qui échouent**

Créer `backend/tests/Feature/PendingSaleValidationTest.php` :
```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class PendingSaleValidationTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    private function makePendingSale($vendeur, $product, int $qty = 2): array
    {
        Sanctum::actingAs($vendeur);
        $response = $this->postJson('/api/sales/pending', [
            'items'     => [['product_id' => $product->id, 'quantity' => $qty]],
            'sale_type' => 'detail',
        ]);

        return $response->json('data');
    }

    public function test_la_liste_des_ventes_en_attente_est_visible(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product);

        $cashier = $this->makeUser('caissier');
        Sanctum::actingAs($cashier);
        $response = $this->getJson('/api/sales/pending');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($sale['id'], $response->json('data')[0]['id']);
    }

    public function test_le_caissier_valide_un_panier_tel_quel(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 3); // stock: 17

        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        Sanctum::actingAs($cashier);

        $response = $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes',
            'amount_paid'    => 1500,
        ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('validee', $data['status']);
        $this->assertSame($cashier->name, $data['cashier']);
        $this->assertSame(17, $product->stock->fresh()->quantity, 'le stock ne doit pas rechanger si les articles ne sont pas modifies');
    }

    public function test_le_caissier_peut_modifier_les_articles_avant_encaissement(): void
    {
        $vendeur  = $this->makeUser('vendeur');
        $product1 = $this->makeProduct(retail: 500, stockQty: 20);
        $product2 = $this->makeProduct(retail: 300, stockQty: 20);
        $sale     = $this->makePendingSale($vendeur, $product1, qty: 3); // stock product1: 17

        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        Sanctum::actingAs($cashier);

        $response = $this->postJson("/api/sales/{$sale['id']}/validate", [
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 1],
                ['product_id' => $product2->id, 'quantity' => 2],
            ],
            'sale_type'      => 'detail',
            'payment_method' => 'especes',
            'amount_paid'    => 1100,
        ]);

        $response->assertOk();
        $this->assertSame(1100, $response->json('data.total'));
        $this->assertSame(19, $product1->stock->fresh()->quantity, '17 restitue a 20 puis redecompte de 1 = 19');
        $this->assertSame(18, $product2->stock->fresh()->quantity, '20 - 2 = 18');
    }

    public function test_montant_insuffisant_en_especes_rejette_la_validation(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 2);

        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        Sanctum::actingAs($cashier);

        $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes',
            'amount_paid'    => 500,
        ])->assertStatus(422);
    }

    public function test_impossible_de_valider_deux_fois(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 2);

        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        Sanctum::actingAs($cashier);

        $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes', 'amount_paid' => 1000,
        ])->assertOk();

        $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes', 'amount_paid' => 1000,
        ])->assertStatus(422);
    }

    public function test_annulation_restitue_le_stock(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 4); // stock: 16

        Sanctum::actingAs($vendeur);
        $response = $this->postJson("/api/sales/{$sale['id']}/cancel-pending");

        $response->assertOk();
        $this->assertSame(20, $product->stock->fresh()->quantity);
    }

    public function test_un_caissier_peut_aussi_annuler(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 2);

        $cashier = $this->makeUser('caissier');
        Sanctum::actingAs($cashier);
        $this->postJson("/api/sales/{$sale['id']}/cancel-pending")->assertOk();
    }

    public function test_caissier_sans_session_ne_peut_pas_valider(): void
    {
        $vendeur = $this->makeUser('vendeur');
        $product = $this->makeProduct(retail: 500, stockQty: 20);
        $sale    = $this->makePendingSale($vendeur, $product, qty: 2);

        $cashier = $this->makeUser('caissier'); // pas de session ouverte
        Sanctum::actingAs($cashier);

        $this->postJson("/api/sales/{$sale['id']}/validate", [
            'payment_method' => 'especes', 'amount_paid' => 1000,
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `php artisan test --filter=PendingSaleValidationTest`
Attendu : FAIL — 404 (routes inexistantes).

- [ ] **Step 3: Routes**

Dans `backend/routes/api.php`, groupe `sales`, ajouter `pending` (GET, avant `{sale}`) et les deux routes imbriquées (après `{sale}/refund`, peu importe l'ordre car non ambiguës) :
```php
        Route::get('pending', [SaleController::class, 'pending']);
```
```php
        Route::post('{sale}/validate', [SaleController::class, 'validatePending']);
        Route::post('{sale}/cancel-pending', [SaleController::class, 'cancelPending']);
```
(Le nom de méthode est `validatePending`, jamais `validate` seul — `validate` est une méthode déjà fournie par le trait `ValidatesRequests` que `Controller` utilise, la nommer ainsi créerait un conflit.)

- [ ] **Step 4: `pending()`, `validatePending()`, `cancelPending()`**

Dans `SaleController.php`, ajouter après `storePending()` :
```php
    public function pending(): JsonResponse
    {
        $sales = Sale::where('status', 'en_attente')
            ->with(['items.product', 'vendor:id,name'])
            ->oldest()
            ->get();

        return $this->success($sales->map(fn($s) => $this->formatSale($s)));
    }

    public function validatePending(Request $request, Sale $sale): JsonResponse
    {
        if ($sale->status !== 'en_attente') {
            return $this->error('Cette vente n\'est plus en attente.', 422);
        }

        $request->validate([
            'items'                  => 'nullable|array|min:1',
            'items.*.product_id'     => 'required_with:items|exists:products,id',
            'items.*.quantity'       => 'required_with:items|integer|min:1',
            'sale_type'              => 'nullable|in:detail,gros',
            'payment_method'         => 'required|in:especes,mobile_money',
            'amount_paid'            => 'required|integer|min:0',
            'mobile_money_number'    => 'required_if:payment_method,mobile_money|nullable|string',
            'discount_type'          => 'nullable|in:percent,fixed',
            'discount_value'         => 'nullable|integer|min:0',
            'notes'                  => 'nullable|string',
        ]);

        $session = CashSession::where('cashier_id', $request->user()->id)
            ->whereNull('closed_at')
            ->first();

        if (!$session && $request->user()->hasRole('caissier')) {
            return $this->error('Vous devez ouvrir une session de caisse avant d\'encaisser.', 422);
        }

        $saleType = $request->sale_type ?? $sale->sale_type;

        if ($request->has('items')) {
            // Restituer le stock des articles actuels avant de recalculer
            $sale->load('items.product.stock');
            foreach ($sale->items as $oldItem) {
                $oldItem->product?->stock?->increment('quantity', $oldItem->quantity);
            }

            $productIds = collect($request->items)->pluck('product_id');
            $products   = Product::with(['price', 'stock'])->whereIn('id', $productIds)->get()->keyBy('id');

            $subtotal  = 0;
            $lineItems = [];

            foreach ($request->items as $item) {
                $product = $products->get($item['product_id']);

                if (!$product || !$product->is_active) {
                    return $this->error("Produit ID {$item['product_id']} indisponible.", 422);
                }

                $stockQty = $product->stock?->quantity ?? 0;
                if ($stockQty < $item['quantity']) {
                    return $this->error(
                        "Stock insuffisant pour \"{$product->name}\" : {$stockQty} disponible(s), {$item['quantity']} demandé(s).",
                        422
                    );
                }

                $price = $product->price;
                if ($saleType === 'gros') {
                    if ($item['quantity'] < $price->wholesale_min_qty) {
                        return $this->error(
                            "\"{$product->name}\" nécessite min. {$price->wholesale_min_qty} unité(s) pour le prix gros.",
                            422
                        );
                    }
                    $unitPrice = $price->wholesale_price;
                } else {
                    $unitPrice = $price->retail_price;
                }

                $lineTotal  = $unitPrice * $item['quantity'];
                $subtotal  += $lineTotal;

                $lineItems[] = [
                    'product'    => $product,
                    'quantity'   => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'total'      => $lineTotal,
                ];
            }
        } else {
            $subtotal  = $sale->subtotal;
            $lineItems = null; // aucun changement d'articles
        }

        $discountType  = $request->discount_type ?? $sale->discount_type;
        $discountValue = $request->has('discount_type') ? ($request->discount_value ?? 0) : $sale->discount_value;

        $discountAmount = 0;
        if ($discountType && $discountValue > 0) {
            $discountAmount = $discountType === 'percent'
                ? (int) round($subtotal * $discountValue / 100)
                : $discountValue;

            $seuilPct = (int) Setting::getValue('remise_max_sans_auth', 10);
            $discountPct = ($subtotal > 0) ? ($discountAmount / $subtotal * 100) : 0;

            if ($discountPct > $seuilPct && !$request->user()->hasRole('proprietaire')) {
                return $this->error(
                    "Remise de " . round($discountPct, 1) . "% dépasse le seuil autorisé ({$seuilPct}%). Autorisation du propriétaire requise.",
                    403
                );
            }
        }

        $total     = max(0, $subtotal - $discountAmount);
        $changeDue = max(0, $request->amount_paid - $total);

        if ($request->payment_method === 'especes' && $request->amount_paid < $total) {
            return $this->error("Montant reçu ({$request->amount_paid} FCFA) insuffisant. Total dû : {$total} FCFA.", 422);
        }

        DB::transaction(function () use ($request, $sale, $session, $saleType, $lineItems, $subtotal, $discountType, $discountValue, $total, $changeDue) {
            if ($lineItems !== null) {
                $sale->items()->delete();
                foreach ($lineItems as $line) {
                    SaleItem::create([
                        'sale_id'    => $sale->id,
                        'product_id' => $line['product']->id,
                        'quantity'   => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'total'      => $line['total'],
                    ]);
                    $line['product']->stock->decrement('quantity', $line['quantity']);
                }
            }

            $sale->update([
                'cashier_id'          => $request->user()->id,
                'cash_session_id'     => $session?->id,
                'status'              => 'validee',
                'sale_type'           => $saleType,
                'payment_method'      => $request->payment_method,
                'mobile_money_number' => $request->mobile_money_number,
                'subtotal'            => $subtotal,
                'discount_type'       => $discountType,
                'discount_value'      => $discountValue ?? 0,
                'total'               => $total,
                'amount_paid'         => $request->amount_paid,
                'change_given'        => $changeDue,
                'notes'               => $request->notes ?? $sale->notes,
            ]);
        });

        activity_log($request->user()->id, 'validation_vente_attente', 'Sale', $sale->id, [
            'total' => $total,
        ]);

        return $this->success(
            $this->formatSale($sale->fresh()->load(['items.product', 'cashier:id,name', 'vendor:id,name'])),
            'Vente validée.'
        );
    }

    public function cancelPending(Request $request, Sale $sale): JsonResponse
    {
        if ($sale->status !== 'en_attente') {
            return $this->error('Cette vente n\'est plus en attente.', 422);
        }

        if ($sale->vendor_id !== $request->user()->id && !$request->user()->hasAnyRole(['caissier', 'gestionnaire', 'proprietaire'])) {
            return $this->error('Vous n\'êtes pas autorisé à annuler ce panier.', 403);
        }

        $sale->load('items.product.stock');

        DB::transaction(function () use ($sale) {
            foreach ($sale->items as $item) {
                $item->product?->stock?->increment('quantity', $item->quantity);
            }
            $sale->update(['status' => 'annulee']);
        });

        activity_log($request->user()->id, 'annulation_vente_attente', 'Sale', $sale->id, [
            'receipt_number' => $sale->receipt_number,
        ]);

        return $this->success(null, 'Panier annulé, stock restitué.');
    }
```

- [ ] **Step 5: Vérifier le passage**

Run: `php artisan test --filter=PendingSale` — Attendu : 11 PASS (3 de la Task 1 + 8 de cette tâche).
Run: `php artisan test` — Attendu : tous PASS (47 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/SaleController.php routes/api.php tests/Feature/PendingSaleValidationTest.php
git commit -m "feat: liste, validation et annulation des ventes en attente cote caisse"
```

---

### Task 3: Frontend — page `vente-tablette.html` + navigation

**Files:**
- Create: `frontend/vente-tablette.html`
- Modify: `frontend/dashboard.html`, `frontend/products.html`, `frontend/stock.html`, `frontend/inventory-count.html`, `frontend/pos.html`, `frontend/reports.html`, `frontend/users.html`, `frontend/profile.html`, `frontend/settings.html`

**Interfaces:**
- Consumes: `POST /sales/pending` (Task 1), helpers `api.get/post`, `toast`, `escHtml`, `fmt`, `refreshIcons`, `requireAuth`.
- Produces: rien (page terminale pour le vendeur).

- [ ] **Step 1: Ajouter le lien de navigation sur les 9 pages existantes**

Dans **chacun** de `frontend/dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html`, `profile.html`, `settings.html`, remplacer (bloc identique dans les 9 fichiers, présent une seule fois chacun) :
```html
    <a href="pos.html" class="nav-item">
      <span class="nav-icon-wrap"><i data-lucide="store" class="icon"></i></span> <span class="nav-label">Caisse (POS)</span>
    </a>
    <div class="sidebar-section" data-role="proprietaire,gestionnaire">Stock</div>
```
par :
```html
    <a href="pos.html" class="nav-item">
      <span class="nav-icon-wrap"><i data-lucide="store" class="icon"></i></span> <span class="nav-label">Caisse (POS)</span>
    </a>
    <a href="vente-tablette.html" class="nav-item">
      <span class="nav-icon-wrap"><i data-lucide="tablet-smartphone" class="icon"></i></span> <span class="nav-label">Vente (tablette)</span>
    </a>
    <div class="sidebar-section" data-role="proprietaire,gestionnaire">Stock</div>
```

- [ ] **Step 2: Créer `frontend/vente-tablette.html`**

```html
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Vente (tablette) — Boutique D</title>
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#2563EB">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@0.462.0/dist/umd/lucide.js" integrity="sha384-WBRt9V/J/erVtkEuP91HUFRv9MvHzFiFOp4/zTDp4xkcMG7aOeIv2asTV4yxFLWa" crossorigin="anonymous"></script>
  <link rel="stylesheet" href="css/app.css">
  <style>
    .vt-layout { display: grid; grid-template-columns: 1fr 340px; gap: 16px; height: calc(100dvh - var(--nav-h) - 32px); }
    .vt-grid { overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; align-content: start; }
    .vt-product { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; cursor: pointer; transition: border-color .15s, transform .1s; }
    .vt-product:hover { border-color: var(--accent); }
    .vt-product:active { transform: scale(.97); }
    .vt-product .name { font-weight: 600; font-size: .85rem; margin-bottom: 4px; }
    .vt-product .price { color: var(--accent); font-weight: 700; }
    .vt-cart { display: flex; flex-direction: column; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
    .vt-cart-items { flex: 1; overflow-y: auto; padding: 12px; }
    @media (max-width: 900px) { .vt-layout { grid-template-columns: 1fr; height: auto; } }
  </style>
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

  <main class="main" style="overflow:hidden;padding:16px">
    <div class="page-title" style="margin-bottom:14px">
      <div class="page-icon" style="background:var(--accent)">
        <i data-lucide="tablet-smartphone" class="icon"></i>
      </div>
      Vente (tablette)
      <small>Préparez un panier, envoyez-le à la caisse</small>
    </div>

    <div class="vt-layout">
      <div>
        <div class="pos-search-bar" style="margin-bottom:12px">
          <div style="position:relative;flex:1">
            <i data-lucide="search" class="icon" style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text-subtle);pointer-events:none"></i>
            <input class="form-control" id="search" type="text" placeholder="Nom ou code-barres…" style="padding-left:38px">
          </div>
          <select class="form-control form-select" id="cat-filter" style="max-width:155px">
            <option value="">Toutes catégories</option>
          </select>
          <select class="form-control form-select" id="type-filter" style="max-width:120px">
            <option value="detail">Détail</option>
            <option value="gros">Gros</option>
          </select>
        </div>
        <div class="vt-grid" id="products-grid">
          <div class="skeleton" style="height:80px;border-radius:10px"></div>
          <div class="skeleton" style="height:80px;border-radius:10px"></div>
          <div class="skeleton" style="height:80px;border-radius:10px"></div>
          <div class="skeleton" style="height:80px;border-radius:10px"></div>
        </div>
      </div>

      <div class="vt-cart">
        <div class="cart-header">
          <span class="cart-title">
            <i data-lucide="shopping-cart" class="icon"></i> Panier
            <span class="cart-count" id="cart-count">0</span>
          </span>
          <button class="btn btn-sm btn-ghost" id="btn-clear-cart" style="font-size:.8rem">
            <i data-lucide="trash-2" class="icon"></i> Vider
          </button>
        </div>
        <div class="vt-cart-items" id="cart-items">
          <div class="empty-state">
            <div class="icon-wrap"><i data-lucide="shopping-cart" class="icon"></i></div>
            <h4>Panier vide</h4>
            <span class="text-muted" style="font-size:.8rem">Touchez un produit pour l'ajouter</span>
          </div>
        </div>
        <div class="cart-summary" id="cart-summary" style="display:none;padding:12px">
          <div class="summary-line"><span class="text-muted">Sous-total</span><span id="s-subtotal">0 FCFA</span></div>
          <div class="summary-total"><span>TOTAL</span><span id="s-total">0 FCFA</span></div>
        </div>
        <div style="padding:12px;border-top:1px solid var(--border-soft)">
          <button class="btn btn-primary btn-block btn-lg" id="btn-send-to-cashier" disabled>
            <i data-lucide="send" class="icon"></i> Envoyer à la caisse
          </button>
        </div>
      </div>
    </div>
  </main>
</div>

<script src="js/api.js"></script>
<script src="js/app.js"></script>
<script>
requireAuth();

let allProducts = [];
let cart = [];

async function init() {
  await loadProducts();
  await loadCategories();
  bindEvents();
}

async function loadProducts() {
  const grid = document.getElementById('products-grid');
  try {
    allProducts = await api.get('/products?is_active=1');
    renderProducts();
  } catch (err) {
    toast(err.message, 'danger');
    grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><div class="icon-wrap"><i data-lucide="triangle-alert" class="icon"></i></div><h4>Erreur de chargement</h4></div>';
    refreshIcons();
  }
}

async function loadCategories() {
  try {
    const cats = await api.get('/categories');
    const select = document.getElementById('cat-filter');
    cats.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = c.name;
      select.appendChild(opt);
    });
  } catch { /* silencieux : filtre non bloquant */ }
}

function getUnitPrice(product) {
  const type = document.getElementById('type-filter').value;
  return type === 'gros' ? (product.prices?.wholesale_price ?? product.prices?.retail_price ?? 0) : (product.prices?.retail_price ?? 0);
}

function renderProducts() {
  const grid = document.getElementById('products-grid');
  const query = document.getElementById('search').value.trim().toLowerCase();
  const catId = document.getElementById('cat-filter').value;

  const filtered = allProducts.filter(p => {
    if (catId && String(p.category_id) !== catId) return false;
    if (query && !p.name.toLowerCase().includes(query) && !(p.barcode ?? '').includes(query)) return false;
    return true;
  });

  if (!filtered.length) {
    grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><div class="icon-wrap"><i data-lucide="package" class="icon"></i></div><h4>Aucun produit</h4></div>';
    refreshIcons();
    return;
  }

  grid.innerHTML = filtered.map(p => `
    <div class="vt-product" data-id="${p.id}">
      <div class="name">${escHtml(p.name)}</div>
      <div class="price">${fmt(getUnitPrice(p))}</div>
      <div class="text-muted font-sm">${p.stock_quantity ?? 0} ${escHtml(p.unit)}</div>
    </div>`).join('');

  grid.querySelectorAll('.vt-product').forEach(el => {
    el.addEventListener('click', () => addToCart(Number(el.dataset.id)));
  });
}

function addToCart(productId) {
  const product = allProducts.find(p => p.id === productId);
  if (!product) return;
  const maxQty = product.stock_quantity ?? 0;
  const existing = cart.find(i => i.product.id === productId);
  if (existing) {
    if (existing.qty >= maxQty) { toast(`Stock maximum atteint (${maxQty} ${escHtml(product.unit)})`, 'warning'); return; }
    existing.qty++;
  } else {
    if (maxQty <= 0) { toast('Produit en rupture de stock.', 'warning'); return; }
    cart.push({ product, qty: 1, unitPrice: getUnitPrice(product) });
  }
  renderCart();
}

function removeFromCart(idx) { cart.splice(idx, 1); renderCart(); }

function updateQty(idx, delta) {
  const item = cart[idx];
  const newQty = item.qty + delta;
  const maxQty = item.product.stock_quantity ?? 0;
  if (newQty <= 0) { removeFromCart(idx); return; }
  if (newQty > maxQty) { toast(`Stock insuffisant (${maxQty} dispo)`, 'warning'); return; }
  item.qty = newQty;
  renderCart();
}

function renderCart() {
  const container = document.getElementById('cart-items');
  const summary    = document.getElementById('cart-summary');
  const countEl    = document.getElementById('cart-count');
  const sendBtn    = document.getElementById('btn-send-to-cashier');

  countEl.textContent = cart.reduce((s, i) => s + i.qty, 0);

  if (!cart.length) {
    container.innerHTML = '<div class="empty-state"><div class="icon-wrap"><i data-lucide="shopping-cart" class="icon"></i></div><h4>Panier vide</h4><span class="text-muted" style="font-size:.8rem">Touchez un produit pour l\'ajouter</span></div>';
    summary.style.display = 'none';
    sendBtn.disabled = true;
    refreshIcons();
    return;
  }

  container.innerHTML = cart.map((item, idx) => `
    <div class="cart-item">
      <div>
        <div class="cart-item-name">${escHtml(item.product.name)}</div>
        <div class="cart-item-unit">${fmt(item.unitPrice)} / ${escHtml(item.product.unit)}</div>
      </div>
      <div class="qty-ctrl">
        <button class="qty-btn" data-action="dec" data-idx="${idx}">−</button>
        <span class="qty-val">${item.qty}</span>
        <button class="qty-btn" data-action="inc" data-idx="${idx}">+</button>
      </div>
      <div class="cart-item-total">${fmt(item.qty * item.unitPrice)}</div>
      <span class="cart-item-del" data-del="${idx}"><i data-lucide="trash-2" class="icon"></i></span>
    </div>`).join('');
  refreshIcons();

  container.querySelectorAll('[data-action]').forEach(btn => {
    btn.addEventListener('click', () => updateQty(Number(btn.dataset.idx), btn.dataset.action === 'inc' ? 1 : -1));
  });
  container.querySelectorAll('[data-del]').forEach(btn => {
    btn.addEventListener('click', () => removeFromCart(Number(btn.dataset.del)));
  });

  const subtotal = cart.reduce((s, i) => s + i.qty * i.unitPrice, 0);
  summary.style.display = 'block';
  document.getElementById('s-subtotal').textContent = fmt(subtotal);
  document.getElementById('s-total').textContent    = fmt(subtotal);
  sendBtn.disabled = false;
}

async function sendToCashier() {
  const btn = document.getElementById('btn-send-to-cashier');
  btn.disabled = true;
  btn.innerHTML = '<span class="loading-spinner"></span>';

  const items = cart.map(i => ({ product_id: i.product.id, quantity: i.qty }));
  const body = { sale_type: document.getElementById('type-filter').value, items };

  try {
    await api.post('/sales/pending', body);
    toast('Panier envoyé à la caisse.', 'success');
    cart = [];
    renderCart();
    await loadProducts();
  } catch (err) {
    toast(err.message, 'danger');
  } finally {
    btn.disabled = cart.length === 0;
    btn.innerHTML = '<i data-lucide="send" class="icon"></i> Envoyer à la caisse';
    refreshIcons();
  }
}

function bindEvents() {
  let searchTimer;
  document.getElementById('search').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(renderProducts, 250);
  });
  document.getElementById('cat-filter').addEventListener('change', renderProducts);
  document.getElementById('type-filter').addEventListener('change', () => {
    cart.forEach(i => { i.unitPrice = getUnitPrice(i.product); });
    renderProducts();
    renderCart();
  });
  document.getElementById('btn-clear-cart').addEventListener('click', () => {
    if (cart.length && !confirm('Vider le panier ?')) return;
    cart = [];
    renderCart();
  });
  document.getElementById('btn-send-to-cashier').addEventListener('click', sendToCashier);
}

init();
</script>
</body>
</html>
```

- [ ] **Step 3: Vérification statique**

1. `grep -c "href=\"vente-tablette.html\"" frontend/*.html` → 1 dans chacun des 9 fichiers modifiés + 1 dans `vente-tablette.html` lui-même (auto-référence, normale).
2. `node --check` sur le JS inline de `vente-tablette.html`.
3. `grep -c "bi bi-" frontend/vente-tablette.html` → 0.

- [ ] **Step 4: Vérification manuelle**

1. Serveurs démarrés, se connecter avec un compte vendeur (créer un utilisateur rôle `vendeur` via la page Utilisateurs si besoin).
2. Le lien « Vente (tablette) » apparaît dans la barre latérale.
3. Ajouter 2 produits au panier, ajuster une quantité, cliquer « Envoyer à la caisse » → toast de succès, panier vidé.
4. Vérifier via `GET /api/sales/pending` (authentifié en tant que caissier, par exemple depuis la console du navigateur) que le panier apparaît avec le statut `en_attente`.

- [ ] **Step 5: Commit**

```bash
git add frontend/vente-tablette.html frontend/dashboard.html frontend/products.html frontend/stock.html frontend/inventory-count.html frontend/pos.html frontend/reports.html frontend/users.html frontend/profile.html frontend/settings.html
git commit -m "feat: page vente tablette pour les vendeurs + navigation"
```

---

### Task 4: Frontend — « Ventes en attente » côté caisse

**Files:**
- Modify: `frontend/pos.html`

**Interfaces:**
- Consumes: `GET /sales/pending`, `POST /sales/{id}/validate`, `POST /sales/{id}/cancel-pending` (Task 2), variables globales existantes `cart`, `discount`, `allProducts`, `session`, fonctions existantes `renderCart()`, `openPayment()`, `processSale()`, `showReceipt()`, `openModal`/`closeModal`, `toast`, `escHtml`, `fmt`.
- Produces: rien (fonctionnalité terminale).
- `pendingSaleId` — nouvelle variable globale (`null` par défaut) : quand non nulle, `processSale()` appelle `POST /sales/{pendingSaleId}/validate` au lieu de `POST /sales`.

- [ ] **Step 1: Bouton d'en-tête**

Dans le bandeau de session, remplacer :
```html
        <button class="btn btn-sm btn-outline hidden" id="btn-close-session">
          <i data-lucide="lock" class="icon"></i> Fermer
        </button>
        <button class="btn" id="btn-refund" onclick="openRefundModal()"><i data-lucide="rotate-ccw" class="icon"></i> Remboursement</button>
      </div>
```
par :
```html
        <button class="btn btn-sm btn-outline hidden" id="btn-close-session">
          <i data-lucide="lock" class="icon"></i> Fermer
        </button>
        <button class="btn" id="btn-pending-sales" onclick="openPendingSalesModal()">
          <i data-lucide="clock" class="icon"></i> En attente
          <span class="badge badge-accent hidden" id="pending-count-badge" style="margin-left:4px">0</span>
        </button>
        <button class="btn" id="btn-refund" onclick="openRefundModal()"><i data-lucide="rotate-ccw" class="icon"></i> Remboursement</button>
      </div>
```

- [ ] **Step 2: Markup de la modal**

À côté de la modal `#refund-modal` existante (avant les balises de script), ajouter :
```html
<div class="modal-backdrop" id="pending-sales-modal">
  <div class="modal" style="max-width:640px">
    <div class="modal-header">
      <div class="modal-title"><i data-lucide="clock" class="icon"></i> Ventes en attente</div>
      <button class="modal-close" data-dismiss><i data-lucide="x" class="icon"></i></button>
    </div>
    <div class="modal-body">
      <div id="pending-sales-list"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" data-dismiss>Fermer</button>
    </div>
  </div>
</div>
```

- [ ] **Step 3: JS — chargement, chargement dans le panier, annulation**

Ajouter dans le script inline de `pos.html` :
```javascript
let pendingSaleId = null;
let pendingSalesCache = [];

async function loadPendingSales() {
  try {
    pendingSalesCache = await api.get('/sales/pending');
    const badge = document.getElementById('pending-count-badge');
    if (pendingSalesCache.length > 0) {
      badge.textContent = pendingSalesCache.length;
      badge.classList.remove('hidden');
    } else {
      badge.classList.add('hidden');
    }
  } catch { /* silencieux : badge non critique */ }
}

function openPendingSalesModal() {
  renderPendingSalesList();
  openModal('pending-sales-modal');
}

function renderPendingSalesList() {
  const container = document.getElementById('pending-sales-list');
  if (!pendingSalesCache.length) {
    container.innerHTML = '<div class="empty-state"><div class="icon-wrap"><i data-lucide="clock" class="icon"></i></div><h4>Aucune vente en attente</h4></div>';
    refreshIcons();
    return;
  }
  container.innerHTML = pendingSalesCache.map(s => `
    <div class="flex justify-between items-center" style="padding:10px 0;border-bottom:1px solid var(--border-soft)">
      <div>
        <strong>${escHtml(s.vendor ?? 'Vendeur')}</strong>
        <div class="text-muted font-sm">${s.items.length} article(s) — ${fmt(s.total)} — ${escHtml(s.date)}</div>
      </div>
      <div class="flex gap-2">
        <button class="btn btn-sm btn-primary" onclick="loadPendingSaleIntoCart(${s.id})"><i data-lucide="download" class="icon"></i> Charger</button>
        <button class="btn btn-sm btn-ghost" onclick="cancelPendingSale(${s.id})"><i data-lucide="x" class="icon"></i></button>
      </div>
    </div>`).join('');
  refreshIcons();
}

function loadPendingSaleIntoCart(saleId) {
  const sale = pendingSalesCache.find(s => s.id === saleId);
  if (!sale) return;
  if (cart.length && !confirm('Le panier actuel sera remplacé par celui du vendeur. Continuer ?')) return;

  cart = sale.items.map(i => ({
    product: allProducts.find(p => p.id === i.product_id) || { id: i.product_id, name: i.product, unit: i.unit },
    qty: i.quantity,
    unitPrice: i.unit_price,
  }));
  discount = sale.discount_value > 0 ? { type: 'fixed', value: sale.discount_value } : { type: null, value: 0 };
  pendingSaleId = sale.id;
  document.getElementById('type-filter').value = sale.sale_type;

  renderCart();
  closeModal('pending-sales-modal');
  toast(`Panier de ${sale.vendor ?? 'vendeur'} chargé — ${sale.items.length} article(s).`, 'success');
}

async function cancelPendingSale(saleId) {
  if (!confirm('Annuler ce panier ? Le stock réservé sera restitué.')) return;
  try {
    await api.post(`/sales/${saleId}/cancel-pending`);
    toast('Panier annulé.', 'success');
    await loadPendingSales();
    renderPendingSalesList();
  } catch (err) {
    toast(err.message, 'danger');
  }
}
```

- [ ] **Step 4: Intégration dans `processSale()`**

Remplacer :
```javascript
  try {
    const sale = await api.post('/sales', body);
    closeModal('modal-payment');
    showReceipt(sale);
    cart = [];
    discount = { type: null, value: 0 };
    renderCart();
    await loadProducts();
  } catch (err) {
```
par :
```javascript
  try {
    const sale = pendingSaleId
      ? await api.post(`/sales/${pendingSaleId}/validate`, body)
      : await api.post('/sales', body);
    closeModal('modal-payment');
    showReceipt(sale);
    cart = [];
    discount = { type: null, value: 0 };
    pendingSaleId = null;
    renderCart();
    await loadProducts();
    await loadPendingSales();
  } catch (err) {
```
(Le corps `body` construit par `processSale()` — `sale_type`, `payment_method`, `amount_paid`, `items`, `discount_type`/`discount_value` optionnels — est déjà exactement la forme attendue par `POST /sales/{id}/validate`, aucun changement de `body` nécessaire.)

- [ ] **Step 5: Chargement initial du badge**

Dans `init()` (~ligne 466), ajouter `loadPendingSales()` à la liste des appels parallèles :
```javascript
async function init() {
  await Promise.all([loadSession(), loadProducts(), loadCategories(), loadPendingSales()]);
  bindEvents();
}
```

- [ ] **Step 6: Vérification statique**

1. `node --check` sur le JS inline extrait de `pos.html`.
2. `grep -n "pendingSaleId" frontend/pos.html` → apparaît dans la déclaration + `loadPendingSaleIntoCart` + `processSale`.
3. Confirmer qu'aucune ligne de `renderCart()`, `openPayment()`, `showReceipt()`, la logique de remise (`btn-discount`) n'a été modifiée (elles restent inchangées, seule la cible de soumission change).

- [ ] **Step 7: Vérification manuelle (bout en bout)**

1. Avec le panier créé en Task 3 (vendeur), se connecter en caissier sur `pos.html`, ouvrir la caisse.
2. Cliquer « En attente » → le panier du vendeur apparaît avec le bon total.
3. Cliquer « Charger » → le panier de caisse se remplit avec les mêmes articles/quantités.
4. Modifier une quantité ou ajouter un article (test de la modifiabilité) → le total se met à jour normalement.
5. Cliquer Encaisser, choisir Espèces, saisir un montant suffisant, confirmer → reçu affiché normalement (même écran que pour une vente classique), la vente en attente disparaît de la liste.
6. Créer un second panier vendeur, l'annuler depuis la caisse → toast de confirmation, stock du produit revenu à sa valeur d'avant l'envoi (vérifiable sur la page Stock).

- [ ] **Step 8: Commit**

```bash
git add frontend/pos.html
git commit -m "feat: prise en charge des ventes en attente a la caisse (charger, modifier, encaisser, annuler)"
```

---

### Task 5: Vérification finale du module

**Files:** aucun changement de code — vérification uniquement.

- [ ] **Step 1: Suite complète**

Run: `cd backend && php artisan test` — Attendu : tous PASS (47 tests, sortie propre).

- [ ] **Step 2: Non-régression**

Vérifier qu'une vente classique (sans panier en attente) fonctionne toujours à l'identique sur `pos.html` : `SaleController::store()` n'a subi aucune modification (`git diff master -- backend/app/Http/Controllers/Api/SaleController.php` doit montrer uniquement des ajouts de méthodes, pas de diff à l'intérieur de `store()`).

- [ ] **Step 3: Vérification croisée stock**

Noter le stock d'un produit, créer un panier vendeur dessus (stock diminue), l'annuler (stock revient), le recréer et le valider avec des articles modifiés à la caisse (stock cohérent avec les articles finaux, pas les articles d'origine) — comparer avec la page Stock à chaque étape.

- [ ] **Step 4: Commit (si des ajustements ont été faits)**

Sinon, rien à commit — cette tâche clôt le module avant la revue finale de branche.
