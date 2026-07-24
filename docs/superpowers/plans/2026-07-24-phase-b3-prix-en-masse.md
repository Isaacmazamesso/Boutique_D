# Phase B3 — Modification de prix en masse — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre au propriétaire de sélectionner plusieurs produits dans la page Produits et d'ajuster en une seule opération leur prix détail et/ou gros (pourcentage ou montant fixe), avec aperçu avant/après et traçabilité dans l'historique de prix existant.

**Architecture:** Backend : un nouvel endpoint `POST /products/bulk-price-update` sur `ProductController` qui calcule les nouveaux prix, valide qu'aucun ne devient négatif (tout ou rien, transaction), écrit dans `price_history` (table existante, réutilisée telle quelle) et met à jour `product_prices`. Frontend : la page `products.html` déjà chargée avec tous les produits (`products` array) gagne une colonne de sélection (cases à cocher + tout sélectionner), un bouton d'action et une modal qui calcule l'aperçu **côté client** avec la même formule que le backend (pas d'appel réseau pour la prévisualisation), puis soumet l'ajustement une fois confirmé.

**Tech Stack:** Laravel 12 (validation, transactions DB), PHPUnit (`php artisan test`), frontend vanilla JS existant (`api.js`, `app.js`, design system Phase A).

## Global Constraints

- Cahier des charges, module 3.3 : modification de prix en masse. Décision de cadrage (2026-07-24) : sélection par cases à cocher (pas par catégorie entière), ajustement en pourcentage OU montant fixe, ciblant le **prix détail et/ou gros uniquement** (le prix d'achat n'est pas concerné par ce module), avec aperçu avant confirmation.
- Réservé au rôle `proprietaire` (même gate que la modification de prix individuelle existante).
- Traçabilité : chaque changement de prix passe par la table `price_history` existante (mêmes colonnes `old_purchase_price`/`new_purchase_price`/`old_retail_price`/`new_retail_price`/`old_wholesale_price`/`new_wholesale_price`/`reason` — toutes `NOT NULL` sauf `reason`). Le prix d'achat n'étant jamais modifié par ce module, ses colonnes `old`/`new` sont toujours égales.
- Aucune ligne n'est modifiée si UNE SEULE ligne calculée serait négative — l'opération est tout-ou-rien (transaction DB), avec message d'erreur listant les produits en cause.
- Montants entiers FCFA (convention existante) : chaque nouveau prix est arrondi à l'entier le plus proche (`round()`).
- Réutiliser `tests/Support/CreatesShopData.php` (Phase B1) pour les fixtures.
- Branche de travail : `feat/b3-prix-en-masse`, créée depuis `master`. Un commit par tâche. Ne pas pousser vers origin sans demande du client.
- Tests : `cd backend && php artisan test` doit passer en entier (24 tests existants + les nouveaux) à la fin de chaque tâche.
- Ne PAS toucher : `frontend/pos.html`, `frontend/stock.html`, le formulaire d'édition individuelle de produit existant (`editProduct`/`btn-save-product`), ni la logique de prix unitaire déjà en place (`updatePrices()` dans `ProductController`).

## File Structure

- **Modify:** `backend/app/Http/Controllers/Api/ProductController.php` — nouvelle méthode `bulkPriceUpdate()`.
- **Modify:** `backend/routes/api.php` — nouvelle route dans le groupe `role:proprietaire` → `prefix('products')`.
- **Create:** `backend/tests/Feature/BulkPriceUpdateTest.php` — tests de l'endpoint.
- **Modify:** `frontend/products.html` — colonne de sélection, bouton d'action, modal d'ajustement, JS associé.

---

### Task 1: Endpoint `bulkPriceUpdate` (TDD)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/ProductController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/BulkPriceUpdateTest.php`

**Interfaces:**
- Consumes: `tests/Support/CreatesShopData.php` (`makeUser`, `makeProduct`).
- Produces: `POST /api/products/bulk-price-update` — payload `{ product_ids: int[], field: 'retail_price'|'wholesale_price', adjustment_type: 'percent'|'fixed', adjustment_value: number, reason?: string }` ; réponse `{ success, message, data: [{ id, name, old, new }, ...] }`. Consommé par le frontend (Tasks 2-3) avec exactement ces noms de champs.
- Formule de calcul (identique côté frontend, Task 3) : `percent` → `round(old * (1 + value / 100))` ; `fixed` → `round(old + value)`.

- [ ] **Step 1: Écrire les tests qui échouent**

Créer `backend/tests/Feature/BulkPriceUpdateTest.php` :
```php
<?php

namespace Tests\Feature;

use App\Models\PriceHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class BulkPriceUpdateTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_ajustement_pourcentage_applique_au_prix_detail(): void
    {
        $owner = $this->makeUser('proprietaire');
        $p1    = $this->makeProduct(retail: 500);
        $p2    = $this->makeProduct(retail: 1000);

        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [$p1->id, $p2->id],
            'field'            => 'retail_price',
            'adjustment_type'  => 'percent',
            'adjustment_value' => 10,
        ]);

        $response->assertOk();
        $this->assertSame(550, $p1->price->fresh()->retail_price);
        $this->assertSame(1100, $p2->price->fresh()->retail_price);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame(550, collect($data)->firstWhere('id', $p1->id)['new']);
    }

    public function test_ajustement_montant_fixe_applique_au_prix_gros(): void
    {
        $owner = $this->makeUser('proprietaire');
        $p1    = $this->makeProduct(retail: 500); // wholesale = retail - 100 = 400 (voir CreatesShopData::makeProduct)

        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [$p1->id],
            'field'            => 'wholesale_price',
            'adjustment_type'  => 'fixed',
            'adjustment_value' => -50,
        ]);

        $response->assertOk();
        $this->assertSame(350, $p1->price->fresh()->wholesale_price);
    }

    public function test_prix_negatif_rejette_sans_rien_appliquer(): void
    {
        $owner = $this->makeUser('proprietaire');
        $p1    = $this->makeProduct(retail: 500);
        $p2    = $this->makeProduct(retail: 100);

        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [$p1->id, $p2->id],
            'field'            => 'retail_price',
            'adjustment_type'  => 'fixed',
            'adjustment_value' => -200, // p2 : 100 - 200 = -100 (invalide)
        ]);

        $response->assertStatus(422);
        $this->assertSame(500, $p1->price->fresh()->retail_price, 'aucun produit ne doit être modifié si un seul calcul échoue');
        $this->assertSame(100, $p2->price->fresh()->retail_price);
        $this->assertSame(0, PriceHistory::count());
    }

    public function test_necessite_le_role_proprietaire(): void
    {
        $cashier = $this->makeUser('caissier');
        $p1      = $this->makeProduct();

        Sanctum::actingAs($cashier);
        $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [$p1->id],
            'field'            => 'retail_price',
            'adjustment_type'  => 'percent',
            'adjustment_value' => 5,
        ])->assertForbidden();
    }

    public function test_l_historique_de_prix_trace_la_raison(): void
    {
        $owner = $this->makeUser('proprietaire');
        $p1    = $this->makeProduct(retail: 500);

        Sanctum::actingAs($owner);
        $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [$p1->id],
            'field'            => 'retail_price',
            'adjustment_type'  => 'percent',
            'adjustment_value' => 10,
            'reason'           => 'Hausse fournisseur',
        ])->assertOk();

        $entry = PriceHistory::where('product_id', $p1->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame('Hausse fournisseur', $entry->reason);
        $this->assertSame(500, $entry->old_retail_price);
        $this->assertSame(550, $entry->new_retail_price);
        $this->assertSame($entry->old_purchase_price, $entry->new_purchase_price, 'le prix d\'achat n\'est jamais modifié par ce module');
    }

    public function test_produit_inexistant_rejette_422(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $this->postJson('/api/products/bulk-price-update', [
            'product_ids'      => [999999],
            'field'            => 'retail_price',
            'adjustment_type'  => 'percent',
            'adjustment_value' => 5,
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `cd backend && php artisan test --filter=BulkPriceUpdateTest`
Attendu : FAIL — 404 (route inexistante).

- [ ] **Step 3: Ajouter la route**

Dans `backend/routes/api.php`, dans le groupe `Route::middleware('role:proprietaire')->prefix('products')->group(function () {...})` (celui qui contient déjà `POST /`, `PUT {product}`, etc.), ajouter :
```php
        Route::post('bulk-price-update', [ProductController::class, 'bulkPriceUpdate']);
```

- [ ] **Step 4: Ajouter l'import et la méthode**

En haut de `ProductController.php`, ajouter :
```php
use Illuminate\Support\Facades\DB;
```

Ajouter la méthode (après `destroy()`, avant `findByBarcode()`) :
```php
    public function bulkPriceUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'product_ids'      => 'required|array|min:1',
            'product_ids.*'    => 'integer|exists:products,id',
            'field'            => 'required|in:retail_price,wholesale_price',
            'adjustment_type'  => 'required|in:percent,fixed',
            'adjustment_value' => 'required|numeric',
            'reason'           => 'nullable|string|max:255',
        ]);

        $field = $request->field;
        $type  = $request->adjustment_type;
        $value = (float) $request->adjustment_value;

        $products = Product::whereIn('id', $request->product_ids)->with('price')->get();

        // Calcul et validation de TOUS les nouveaux prix avant toute écriture (tout-ou-rien).
        $computed = [];
        $errors   = [];

        foreach ($products as $product) {
            $price = $product->price;
            if (!$price) continue;

            $old = $price->$field;
            $new = $type === 'percent'
                ? (int) round($old * (1 + $value / 100))
                : (int) round($old + $value);

            if ($new < 0) {
                $errors[] = "{$product->name} : nouveau prix négatif ({$new} FCFA).";
                continue;
            }

            $computed[] = ['product' => $product, 'price' => $price, 'old' => $old, 'new' => $new];
        }

        if (!empty($errors)) {
            return $this->error('Ajustement refusé : ' . implode(' ', $errors), 422);
        }

        $updated = [];

        DB::transaction(function () use ($computed, $field, $request, &$updated) {
            foreach ($computed as $entry) {
                $product = $entry['product'];
                $price   = $entry['price'];

                if ($entry['old'] !== $entry['new']) {
                    PriceHistory::create([
                        'product_id'          => $product->id,
                        'changed_by'          => $request->user()->id,
                        'old_purchase_price'  => $price->purchase_price,
                        'new_purchase_price'  => $price->purchase_price,
                        'old_retail_price'    => $price->retail_price,
                        'new_retail_price'    => $field === 'retail_price' ? $entry['new'] : $price->retail_price,
                        'old_wholesale_price' => $price->wholesale_price,
                        'new_wholesale_price' => $field === 'wholesale_price' ? $entry['new'] : $price->wholesale_price,
                        'reason'              => $request->reason,
                    ]);

                    $price->update([$field => $entry['new']]);
                }

                $updated[] = [
                    'id'   => $product->id,
                    'name' => $product->name,
                    'old'  => $entry['old'],
                    'new'  => $entry['new'],
                ];
            }
        });

        activity_log($request->user()->id, 'modification_prix_masse', null, null, [
            'nb_produits'      => count($updated),
            'field'            => $field,
            'adjustment_type'  => $type,
            'adjustment_value' => $value,
            'product_ids'      => array_column($updated, 'id'),
        ]);

        return $this->success($updated, count($updated) . ' produit(s) mis à jour.');
    }
```

- [ ] **Step 5: Vérifier le passage**

Run: `php artisan test --filter=BulkPriceUpdateTest` — Attendu : 6 PASS.
Run: `php artisan test` — Attendu : tous PASS (30 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ProductController.php routes/api.php tests/Feature/BulkPriceUpdateTest.php
git commit -m "feat: endpoint de modification de prix en masse (produits selectionnes)"
```

---

### Task 2: Frontend — sélection multiple dans le tableau Produits

**Files:**
- Modify: `frontend/products.html`

**Interfaces:**
- Consumes: `products` array global déjà chargé par `loadProducts()`, `renderTable()`, `refreshIcons()`, `isOwner`.
- Produces (consommés par Task 3) :
  - `let selectedProductIds = new Set()` — variable globale contenant les ids actuellement cochés.
  - `function toggleProductSelect(id, checked)` — ajoute/retire un id de `selectedProductIds` et appelle `updateBulkButtonState()`.
  - `function toggleSelectAll(checked)` — coche/décoche toutes les cases actuellement affichées (produits filtrés visibles) et synchronise `selectedProductIds`.
  - `function updateBulkButtonState()` — active/désactive `#btn-bulk-price` et met à jour le compteur `#bulk-count` selon `selectedProductIds.size`.

- [ ] **Step 1: Ajouter la colonne de sélection dans l'en-tête du tableau**

Dans `frontend/products.html`, dans le `<thead>` de la table produits (~ligne 130), remplacer :
```html
            <tr>
              <th>Nom</th>
              <th>Catégorie</th>
              <th>Unité</th>
              <th>Prix détail</th>
              <th>Prix gros</th>
              <th>Seuil alerte</th>
              <th>Statut</th>
              <th data-role="proprietaire"></th>
            </tr>
```
par :
```html
            <tr>
              <th data-role="proprietaire" style="width:36px"><input type="checkbox" id="select-all-products"></th>
              <th>Nom</th>
              <th>Catégorie</th>
              <th>Unité</th>
              <th>Prix détail</th>
              <th>Prix gros</th>
              <th>Seuil alerte</th>
              <th>Statut</th>
              <th data-role="proprietaire"></th>
            </tr>
```

- [ ] **Step 2: Ajouter le bouton d'action dans le `card-header`**

Dans le `card-header` de la carte Produits (~ligne 103-109), remplacer :
```html
      <div class="card-header">
        <div class="card-title">
          <i data-lucide="tag" class="icon" style="color:var(--accent)"></i> Produits
        </div>
        <button class="btn btn-sm btn-primary" id="btn-add-product" data-role="proprietaire">
          <i data-lucide="plus" class="icon"></i> Produit
        </button>
      </div>
```
par :
```html
      <div class="card-header">
        <div class="card-title">
          <i data-lucide="tag" class="icon" style="color:var(--accent)"></i> Produits
        </div>
        <div class="flex gap-2">
          <button class="btn btn-sm" id="btn-bulk-price" data-role="proprietaire" disabled>
            <i data-lucide="percent" class="icon"></i> Modifier les prix (<span id="bulk-count">0</span>)
          </button>
          <button class="btn btn-sm btn-primary" id="btn-add-product" data-role="proprietaire">
            <i data-lucide="plus" class="icon"></i> Produit
          </button>
        </div>
      </div>
```

- [ ] **Step 3: Ajouter la case à cocher dans chaque ligne**

Dans `loadProducts()`, le template de ligne (~ligne 296), remplacer :
```javascript
      <tr>
        <td>
          <strong>${escHtml(p.name)}</strong>
```
par :
```javascript
      <tr>
        <td data-role="proprietaire"><input type="checkbox" class="product-select" data-id="${p.id}" ${selectedProductIds.has(p.id) ? 'checked' : ''}></td>
        <td>
          <strong>${escHtml(p.name)}</strong>
```

Juste avant la fonction `loadProducts()`, ajouter les variables et fonctions de sélection :
```javascript
let selectedProductIds = new Set();

function toggleProductSelect(id, checked) {
  if (checked) selectedProductIds.add(id); else selectedProductIds.delete(id);
  updateBulkButtonState();
}

function toggleSelectAll(checked) {
  document.querySelectorAll('.product-select').forEach(cb => {
    cb.checked = checked;
    const id = Number(cb.dataset.id);
    if (checked) selectedProductIds.add(id); else selectedProductIds.delete(id);
  });
  updateBulkButtonState();
}

function updateBulkButtonState() {
  const btn = document.getElementById('btn-bulk-price');
  document.getElementById('bulk-count').textContent = selectedProductIds.size;
  btn.disabled = selectedProductIds.size === 0;
}
```

Au début de `loadProducts()` (juste après `showTableSkeleton('products-tbody', 6);`), réinitialiser la sélection — un changement de filtre ou un rechargement repart d'une sélection vide (limite de scope volontaire, évite de garder des ids sélectionnés qui ne correspondent plus à ce qui est affiché) :
```javascript
  selectedProductIds.clear();
  document.getElementById('select-all-products').checked = false;
  updateBulkButtonState();
```

- [ ] **Step 4: Brancher les événements**

Dans `bindEvents()` (fin du fichier), ajouter :
```javascript
  document.getElementById('select-all-products').addEventListener('change', e => toggleSelectAll(e.target.checked));
  document.getElementById('products-tbody').addEventListener('change', e => {
    if (e.target.classList.contains('product-select')) {
      toggleProductSelect(Number(e.target.dataset.id), e.target.checked);
    }
  });
```
(Délégation d'événement sur `#products-tbody` : nécessaire car les lignes sont recréées à chaque `renderTable()`, un `addEventListener` direct sur chaque case serait perdu au rechargement.)

- [ ] **Step 5: Vérification statique**

1. `grep -c "data-role=\"proprietaire\"" frontend/products.html` → augmente de 1 (nouvelle colonne checkbox).
2. `node --check` sur le JS inline extrait de `products.html`.
3. Confirmer que `renderTable()` (dans `app.js`, non modifié) calcule bien le colspan de l'état vide à partir du nombre de `<th>` — 9 désormais, donc aucun changement requis côté `app.js`.

- [ ] **Step 6: Vérification manuelle**

1. Serveurs démarrés, login `admin`/`admin123`, page Produits.
2. La case « tout sélectionner » en haut du tableau coche/décoche toutes les lignes visibles ; le bouton « Modifier les prix (0)» devient « Modifier les prix (N) » et se débloque.
3. Cocher une ligne individuellement incrémente le compteur ; décocher toutes les lignes désactive le bouton.
4. Changer un filtre (catégorie, statut, recherche) → la sélection se réinitialise (comportement voulu).

- [ ] **Step 7: Commit**

```bash
git add frontend/products.html
git commit -m "feat: selection multiple de produits dans le tableau (prealable au prix en masse)"
```

---

### Task 3: Frontend — modal d'ajustement avec aperçu

**Files:**
- Modify: `frontend/products.html`

**Interfaces:**
- Consumes: `selectedProductIds`, `products` (Task 2), `api.post('/products/bulk-price-update', {...})`, `toast()`, `fmt()`, `escHtml()`, `openModal()`/`closeModal()`, `loadProducts()`.
- Produces: rien (fonctionnalité terminale du module).
- Formule de calcul (IDENTIQUE au backend, Task 1) : `percent` → `Math.round(old * (1 + value / 100))` ; `fixed` → `Math.round(old + value)`.

- [ ] **Step 1: Markup de la modal**

Avant `</script>` de fermeture... non — avant la balise `<script src="js/api.js"></script>` (donc dans le HTML, à côté des autres modals `#modal-cat` et `#modal-product`), ajouter :
```html
<!-- Modal prix en masse -->
<div class="modal-backdrop" id="modal-bulk-price">
  <div class="modal" style="max-width:640px">
    <div class="modal-header">
      <span class="modal-title">
        <i data-lucide="percent" class="icon" style="color:var(--accent)"></i> Modifier les prix (<span id="bp-count">0</span> produits)
      </span>
      <button class="modal-close" data-dismiss><i data-lucide="x" class="icon"></i></button>
    </div>
    <div class="modal-body">
      <div class="grid grid-2 gap-3">
        <div class="form-group">
          <label class="form-label">Prix à ajuster</label>
          <select class="form-control form-select" id="bp-field">
            <option value="retail_price">Prix détail</option>
            <option value="wholesale_price">Prix gros</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Type d'ajustement</label>
          <select class="form-control form-select" id="bp-type">
            <option value="percent">Pourcentage (%)</option>
            <option value="fixed">Montant fixe (FCFA)</option>
          </select>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label" id="bp-value-label">Valeur (% — utilisez un signe négatif pour baisser)</label>
          <input class="form-control" id="bp-value" type="number" step="any" placeholder="Ex: 10 ou -5">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Motif (optionnel)</label>
          <input class="form-control" id="bp-reason" type="text" maxlength="255" placeholder="Ex: Hausse fournisseur">
        </div>
      </div>

      <div id="bp-error" class="alert alert-danger hidden mt-4"></div>

      <div class="table-wrap mt-4" style="max-height:280px;overflow-y:auto">
        <table>
          <thead><tr><th>Produit</th><th class="text-right">Actuel</th><th class="text-right">Nouveau</th></tr></thead>
          <tbody id="bp-preview-tbody"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" data-dismiss>Annuler</button>
      <button class="btn btn-primary" id="btn-bulk-price-submit"><i data-lucide="check" class="icon"></i> Appliquer</button>
    </div>
  </div>
</div>
```

- [ ] **Step 2: JS d'ouverture, d'aperçu et de soumission**

Ajouter à la fin du script inline :
```javascript
function computeAdjustedPrice(old, type, value) {
  return type === 'percent'
    ? Math.round(old * (1 + value / 100))
    : Math.round(old + value);
}

function openBulkPriceModal() {
  if (selectedProductIds.size === 0) return;
  document.getElementById('bp-count').textContent = selectedProductIds.size;
  document.getElementById('bp-value').value = '';
  document.getElementById('bp-reason').value = '';
  document.getElementById('bp-error').classList.add('hidden');
  document.getElementById('btn-bulk-price-submit').disabled = false;
  renderBulkPricePreview();
  openModal('modal-bulk-price');
}

function renderBulkPricePreview() {
  const field = document.getElementById('bp-field').value;
  const type  = document.getElementById('bp-type').value;
  const value = parseFloat(document.getElementById('bp-value').value);
  const label = type === 'percent' ? "Valeur (% — utilisez un signe négatif pour baisser)" : "Valeur (FCFA — utilisez un signe négatif pour baisser)";
  document.getElementById('bp-value-label').textContent = label;

  const selected = products.filter(p => selectedProductIds.has(p.id));
  let hasNegative = false;

  const rows = selected.map(p => {
    const old = p.prices?.[field] ?? 0;
    const valid = !Number.isNaN(value);
    const next = valid ? computeAdjustedPrice(old, type, value) : old;
    if (valid && next < 0) hasNegative = true;
    return `<tr>
      <td>${escHtml(p.name)}</td>
      <td class="text-right">${fmt(old)}</td>
      <td class="text-right ${valid && next < 0 ? 'text-danger font-bold' : ''}">${valid ? fmt(next) : '—'}</td>
    </tr>`;
  });

  document.getElementById('bp-preview-tbody').innerHTML = rows.join('');

  const errEl = document.getElementById('bp-error');
  const submitBtn = document.getElementById('btn-bulk-price-submit');
  if (hasNegative) {
    errEl.textContent = 'Au moins un nouveau prix serait négatif — corrigez la valeur avant de continuer.';
    errEl.classList.remove('hidden');
    submitBtn.disabled = true;
  } else {
    errEl.classList.add('hidden');
    submitBtn.disabled = false;
  }
}

async function submitBulkPrice() {
  const field = document.getElementById('bp-field').value;
  const type  = document.getElementById('bp-type').value;
  const value = parseFloat(document.getElementById('bp-value').value);
  const reason = document.getElementById('bp-reason').value.trim();

  if (Number.isNaN(value)) { toast('Saisissez une valeur d\'ajustement.', 'warning'); return; }

  const btn = document.getElementById('btn-bulk-price-submit');
  btn.disabled = true;
  try {
    const result = await api.post('/products/bulk-price-update', {
      product_ids: [...selectedProductIds],
      field,
      adjustment_type: type,
      adjustment_value: value,
      reason: reason || null,
    });
    toast(`${result.length} produit(s) mis à jour.`, 'success');
    closeModal('modal-bulk-price');
    loadProducts();
  } catch (err) {
    toast(err.message, 'danger');
  } finally {
    btn.disabled = false;
  }
}
```

Dans `updateBulkButtonState()` (Task 2), ce n'est pas modifié ici — l'ouverture de la modal se fait via le bouton, pas automatiquement.

- [ ] **Step 3: Brancher les événements**

Dans `bindEvents()`, ajouter :
```javascript
  document.getElementById('btn-bulk-price').addEventListener('click', openBulkPriceModal);
  document.getElementById('btn-bulk-price-submit').addEventListener('click', submitBulkPrice);
  ['bp-field', 'bp-type', 'bp-value'].forEach(id =>
    document.getElementById(id).addEventListener('input', renderBulkPricePreview)
  );
```

- [ ] **Step 4: Vérification statique**

1. `node --check` sur le JS inline extrait de `products.html`.
2. `grep -n "computeAdjustedPrice" frontend/products.html` → 1 définition + 1 usage.
3. Comparer manuellement la formule JS de `computeAdjustedPrice` avec la formule PHP de `bulkPriceUpdate()` (Task 1) — doivent être arithmétiquement identiques (elles le sont : `round(old*(1+v/100))` / `round(old+v)` des deux côtés).

- [ ] **Step 5: Vérification manuelle**

1. Serveurs démarrés, login `admin`/`admin123`, page Produits.
2. Sélectionner 2 produits → cliquer « Modifier les prix » → la modal s'ouvre, le tableau d'aperçu affiche les 2 produits avec leur prix détail actuel et une colonne « Nouveau » vide (aucune valeur saisie).
3. Saisir `10` en pourcentage → la colonne « Nouveau » se met à jour en temps réel avec les prix arrondis +10%.
4. Passer en « Montant fixe », saisir une valeur qui rendrait un prix négatif → la ligne concernée passe en rouge, un message d'erreur apparaît, le bouton Appliquer se désactive.
5. Revenir à une valeur valide, cliquer Appliquer → toast de succès, modal fermée, tableau des produits rafraîchi avec les nouveaux prix visibles dans la colonne « Prix détail ».
6. Vérifier via l'historique de prix (déjà exposé par `GET /products/{id}/price-history`, non modifié par ce plan) que l'entrée a bien été créée — `curl` authentifié suffit, pas besoin d'UI dédiée (hors scope).

- [ ] **Step 6: Commit**

```bash
git add frontend/products.html
git commit -m "feat: modal d'ajustement de prix en masse avec apercu avant confirmation"
```

---

### Task 4: Vérification finale du module

**Files:** aucun changement de code — vérification uniquement.

- [ ] **Step 1: Suite complète**

Run: `cd backend && php artisan test` — Attendu : tous PASS (30 tests, sortie propre).

- [ ] **Step 2: Non-régression**

Vérifier que la modification de prix individuelle (`editProduct` → `btn-save-product`) fonctionne toujours normalement (aucune ligne de cette fonction touchée par ce plan, vérification de confiance).

- [ ] **Step 3: Vérification croisée**

Ajuster un lot de produits de +5% (pourcentage), puis rouvrir la modal d'édition individuelle d'un des produits concernés (`editProduct`) : le prix affiché dans le formulaire doit correspondre au nouveau prix appliqué en masse.

- [ ] **Step 4: Commit (si des ajustements ont été faits)**

Sinon, rien à commit — cette tâche clôt le module avant la revue finale de branche.
