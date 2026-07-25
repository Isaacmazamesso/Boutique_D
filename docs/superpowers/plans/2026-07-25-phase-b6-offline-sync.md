# Phase B6 — Synchronisation offline/online — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre à la caisse (POS) d'encaisser des ventes espèces hors-ligne, mises en file dans IndexedDB, puis synchronisées automatiquement au retour du réseau via l'endpoint `POST /sales` existant, avec déduplication par UUID et résolution manuelle des conflits de stock.

**Architecture:** Un module frontend autonome `frontend/js/offline.js` (IndexedDB + détection de connectivité + bandeau + moteur de synchronisation), chargé sur toutes les pages après `api.js`/`app.js`, sans modifier `app.js`/`api.js`/`sw.js`. `pos.html` branche le chemin hors-ligne dans `processSale()` et rafraîchit le cache catalogue à chaque chargement en ligne. Le seul changement backend est une colonne `sync_uuid` nullable-unique sur `sales` + une déduplication additive en tête de `SaleController::store()`.

**Tech Stack:** Laravel 12 (migration + dédup + PHPUnit), JavaScript vanilla + IndexedDB natif (aucune dépendance), design system Phase A (tokens CSS globaux `var(--...)`).

## Global Constraints

- Spec `docs/superpowers/specs/2026-07-25-phase-b6-offline-sync-design.md`, module 8 du cahier des charges.
- **Un seul changement backend** : colonne `sync_uuid` + déduplication UUID dans `store()` (additif). Aucune autre modification backend.
- `frontend/sw.js`, `frontend/js/app.js`, `frontend/js/api.js` **inchangés**.
- Périmètre offline v1 : **vente au comptoir espèces uniquement**. Mobile money désactivé hors-ligne.
- Conflit de stock au retour du réseau → vente marquée `failed`, résolution manuelle (Réessayer / Abandonner). **Le stock backend n'est jamais négatif** (la vérification de stock existante de `store()` est inchangée).
- Synchro déclenchée automatiquement (événement `online`) **et** par un bouton manuel.
- Notifications = alertes dans l'app (bandeau + `toast()` existant). Pas de push navigateur.
- Montants entiers FCFA. Réponses `{success,message,data}`. Branche de travail : `feat/b6-offline-sync`, créée depuis `master`. Un commit par tâche. Ne pas pousser vers origin sans demande du client.
- Tests : `cd backend && php artisan test` doit passer en entier (47 tests existants + les nouveaux) à la fin de chaque tâche.
- Réutiliser `tests/Support/CreatesShopData.php` (`makeUser`, `makeProduct`, `openSession`).

## File Structure

- **Create:** `backend/database/migrations/2026_07_25_090000_add_sync_uuid_to_sales_table.php`
- **Modify:** `backend/app/Http/Controllers/Api/SaleController.php` — validation `uuid` + dédup en tête de `store()` + `sync_uuid` à la création.
- **Create:** `backend/tests/Feature/SaleSyncDedupTest.php`
- **Create:** `frontend/js/offline.js` — module autonome (IndexedDB + connectivité + bandeau + synchro + résolution).
- **Modify (script tag uniquement):** `frontend/dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html`, `profile.html`, `settings.html`, `vente-tablette.html`, `login.html` — 11 pages.
- **Modify:** `frontend/pos.html` — cache catalogue/session au chargement, chemin hors-ligne dans `processSale()`, reçu hors-ligne, verrouillage mobile money hors-ligne.

---

### Task 1: Backend — colonne `sync_uuid` + déduplication (TDD)

**Files:**
- Create: `backend/database/migrations/2026_07_25_090000_add_sync_uuid_to_sales_table.php`
- Modify: `backend/app/Http/Controllers/Api/SaleController.php`
- Test: `backend/tests/Feature/SaleSyncDedupTest.php`

**Interfaces:**
- Consumes: `tests/Support/CreatesShopData.php` (`makeUser`, `makeProduct`, `openSession`), `Laravel\Sanctum\Sanctum`.
- Produces: `POST /api/sales` accepte un champ optionnel `uuid` (`nullable|uuid`). Si `uuid` déjà présent en base (`sales.sync_uuid`), retourne la vente existante avec HTTP 200 sans créer de doublon. Sinon crée normalement et persiste `sync_uuid`. Le frontend (Task 3) envoie ce champ.

- [ ] **Step 1: Écrire les tests qui échouent**

Créer `backend/tests/Feature/SaleSyncDedupTest.php` :
```php
<?php

namespace Tests\Feature;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class SaleSyncDedupTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    private function saleBody(int $productId, string $uuid): array
    {
        return [
            'uuid'           => $uuid,
            'sale_type'      => 'detail',
            'payment_method' => 'especes',
            'amount_paid'    => 1000,
            'items'          => [['product_id' => $productId, 'quantity' => 1]],
        ];
    }

    public function test_une_vente_avec_uuid_neuf_est_creee_et_persiste_le_sync_uuid(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 10);
        $uuid    = '11111111-1111-4111-8111-111111111111';

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', $this->saleBody($product->id, $uuid));

        $response->assertCreated();
        $this->assertSame(1, Sale::count());
        $this->assertSame($uuid, Sale::first()->sync_uuid);
    }

    public function test_rejeu_du_meme_uuid_ne_cree_pas_de_doublon(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 10);
        $uuid    = '22222222-2222-4222-8222-222222222222';

        Sanctum::actingAs($cashier);
        $first  = $this->postJson('/api/sales', $this->saleBody($product->id, $uuid));
        $second = $this->postJson('/api/sales', $this->saleBody($product->id, $uuid));

        $first->assertCreated();
        $second->assertOk(); // 200, pas 201
        $this->assertSame(1, Sale::count(), 'un rejeu ne doit jamais creer une seconde vente');
        // Le rejeu renvoie la vente existante (meme receipt_number)
        $this->assertSame(
            $first->json('data.receipt_number'),
            $second->json('data.receipt_number')
        );
        // Le stock n'a ete decremente qu'une seule fois
        $this->assertSame(9, $product->stock->fresh()->quantity);
    }

    public function test_une_vente_sans_uuid_reste_inchangee(): void
    {
        $cashier = $this->makeUser('caissier');
        $this->openSession($cashier);
        $product = $this->makeProduct(retail: 500, stockQty: 10);

        Sanctum::actingAs($cashier);
        $response = $this->postJson('/api/sales', [
            'sale_type'      => 'detail',
            'payment_method' => 'especes',
            'amount_paid'    => 1000,
            'items'          => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertCreated();
        $this->assertNull(Sale::first()->sync_uuid);
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `cd backend && php artisan test --filter=SaleSyncDedupTest`
Attendu : FAIL — colonne `sync_uuid` inexistante (`SQLSTATE ... no such column: sync_uuid`) ou le second POST renvoie 201 au lieu de 200.

- [ ] **Step 3: Migration**

`backend/database/migrations/2026_07_25_090000_add_sync_uuid_to_sales_table.php` :
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
            $table->uuid('sync_uuid')->nullable()->unique()->after('receipt_number');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('sync_uuid');
        });
    }
};
```
(`uuid()->nullable()->unique()` : les valeurs NULL sont considérées distinctes par l'index unique sous SQLite comme sous PostgreSQL — plusieurs ventes en ligne sans `uuid` restent donc autorisées.)

- [ ] **Step 4: Modèle `Sale`**

Dans `backend/app/Models/Sale.php`, ajouter `'sync_uuid'` au tableau `$fillable` (juste après `'receipt_number'`) :
```php
    protected $fillable = [
        'receipt_number', 'sync_uuid', 'cashier_id', 'vendor_id', 'cash_session_id', 'status',
        'sale_type', 'payment_method', 'mobile_money_number',
        'subtotal', 'discount_type', 'discount_value', 'total',
        'amount_paid', 'change_given', 'notes',
    ];
```

- [ ] **Step 5: Validation + déduplication dans `store()`**

Dans `SaleController::store()`, ajouter la règle de validation `'uuid'` (dans le tableau passé à `$request->validate([...])`, après `'notes'`) :
```php
            'vendor_id'              => 'nullable|exists:users,id',
            'notes'                  => 'nullable|string',
            'uuid'                   => 'nullable|uuid',
        ]);
```

Juste après la fermeture de `$request->validate([...]);` (avant la recherche de session de caisse), insérer la déduplication :
```php
        // Déduplication (synchro offline) : un rejeu du même UUID renvoie la vente déjà créée.
        if ($request->uuid) {
            $existing = Sale::where('sync_uuid', $request->uuid)->first();
            if ($existing) {
                return $this->success(
                    $this->formatSale($existing->load(['items.product', 'cashier:id,name']), collect()),
                    'Vente déjà enregistrée.',
                    200
                );
            }
        }
```

Dans le `Sale::create([...])` de la transaction de `store()`, ajouter `'sync_uuid'` (juste après `'receipt_number'`) :
```php
            $sale = Sale::create([
                'receipt_number'   => $this->generateReceiptNumber(),
                'sync_uuid'        => $request->uuid,
                'cashier_id'       => $request->user()->id,
```
(La closure de la transaction capture déjà `$request` via `use (...)` — aucun changement de signature nécessaire.)

- [ ] **Step 6: Vérifier le passage**

Run: `php artisan test --filter=SaleSyncDedupTest` — Attendu : 3 PASS.
Run: `php artisan test` — Attendu : tous PASS (50 tests).

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/2026_07_25_090000_add_sync_uuid_to_sales_table.php backend/app/Models/Sale.php backend/app/Http/Controllers/Api/SaleController.php backend/tests/Feature/SaleSyncDedupTest.php
git commit -m "feat: deduplication des ventes par sync_uuid sur POST /sales (idempotence synchro offline)"
```

- [ ] **Step 8: Appliquer la migration à la base de dev PostgreSQL**

Run: `php artisan migrate --force`
Attendu : `2026_07_25_090000_add_sync_uuid_to_sales_table ... DONE`.
(Les tests tournent sur SQLite `:memory:` toujours migré à neuf ; la vraie base de dev PostgreSQL ne se met à jour qu'avec cette commande — sans elle, la vérification navigateur de la Task 3 échouera avec une erreur de colonne manquante, comme en Phase B5.)

---

### Task 2: Frontend — module `offline.js` + chargement sur toutes les pages

**Files:**
- Create: `frontend/js/offline.js`
- Modify (une ligne `<script>` chacun) : `frontend/dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html`, `profile.html`, `settings.html`, `vente-tablette.html`, `login.html`

**Interfaces:**
- Consumes: `API_BASE` (const globale déclarée par `js/api.js`, accessible en portée lexicale globale), `toast(msg, type)` (fonction globale de `js/app.js`), `localStorage.getItem('token')`.
- Produces (exposés sur `window`, consommés par `pos.html` en Task 3) :
  - `window.offlineIsOnline()` → `boolean` (`navigator.onLine`).
  - `window.offlineCacheCatalog({products?, categories?, session?})` → `Promise<void>` — écrit chaque clé fournie dans le store `cache`.
  - `window.offlineGetCachedProducts()` → `Promise<Array|null>`.
  - `window.offlineGetCachedCategories()` → `Promise<Array|null>`.
  - `window.offlineGetCachedSession()` → `Promise<Object|null>`.
  - `window.offlineSaveSale(body, display)` → `Promise<void>` — ajoute la vente à la file (`status:'pending'`, `uuid` généré et injecté dans `body`), décrémente le stock du catalogue en cache, met à jour le bandeau. `display` = `{items:[{name,qty,total}], total}` (snapshot pour l'UI de résolution).
  - `window.offlineSyncNow()` → `Promise<void>` — déclenche une passe de synchronisation (identique au déclenchement auto).

- [ ] **Step 1: Créer `frontend/js/offline.js`**

```javascript
// offline.js — synchronisation offline/online (Phase B6)
// Module autonome : IndexedDB + détection de connectivité + bandeau + moteur de synchro.
// Chargé après api.js/app.js sur toutes les pages. Ne modifie ni app.js ni api.js.
(function () {
  'use strict';

  const DB_NAME = 'boutique-d-offline';
  const DB_VERSION = 1;
  let _syncing = false;

  // ── IndexedDB ────────────────────────────────────────────────────────────
  let _dbPromise = null;
  function openDb() {
    if (_dbPromise) return _dbPromise;
    _dbPromise = new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains('cache')) db.createObjectStore('cache', { keyPath: 'key' });
        if (!db.objectStoreNames.contains('pending_sales')) db.createObjectStore('pending_sales', { keyPath: 'local_id', autoIncrement: true });
        if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', { keyPath: 'key' });
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    return _dbPromise;
  }

  function reqAsPromise(request) {
    return new Promise((resolve, reject) => {
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }

  // Exécute fn(store) dans une transaction et résout à la fin de la transaction
  // avec la valeur résolue par fn (utile pour les lectures qui renvoient reqAsPromise).
  function run(storeName, mode, fn) {
    return openDb().then(db => new Promise((resolve, reject) => {
      const t = db.transaction(storeName, mode);
      const s = t.objectStore(storeName);
      let result;
      Promise.resolve(fn(s)).then(r => { result = r; }, reject);
      t.oncomplete = () => resolve(result);
      t.onerror = () => reject(t.error);
      t.onabort = () => reject(t.error);
    }));
  }

  const cachePut = (key, value) => run('cache', 'readwrite', s => s.put({ key, value }));
  const cacheGet = (key) => run('cache', 'readonly', s => reqAsPromise(s.get(key))).then(r => r ? r.value : null);
  const pendingAdd = (entry) => run('pending_sales', 'readwrite', s => reqAsPromise(s.add(entry)));
  const pendingAll = () => run('pending_sales', 'readonly', s => reqAsPromise(s.getAll()));
  const pendingPut = (entry) => run('pending_sales', 'readwrite', s => s.put(entry));
  const pendingDelete = (localId) => run('pending_sales', 'readwrite', s => s.delete(localId));
  const metaPut = (key, value) => run('meta', 'readwrite', s => s.put({ key, value }));

  // ── API publique (window.offline*) ────────────────────────────────────────
  function offlineIsOnline() { return navigator.onLine; }

  async function offlineCacheCatalog(payload) {
    if (!payload) return;
    if ('products' in payload)   await cachePut('products', payload.products);
    if ('categories' in payload) await cachePut('categories', payload.categories);
    if ('session' in payload)    await cachePut('session', payload.session);
  }

  const offlineGetCachedProducts   = () => cacheGet('products');
  const offlineGetCachedCategories = () => cacheGet('categories');
  const offlineGetCachedSession    = () => cacheGet('session');

  function uuidV4() {
    if (crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
      const r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  async function offlineSaveSale(body, display) {
    const uuid = uuidV4();
    const entry = {
      uuid,
      body: Object.assign({}, body, { uuid }),
      created_at: new Date().toISOString(),
      status: 'pending',
      error: null,
      display: display || null,
    };
    await pendingAdd(entry);

    // Décrémenter le stock du catalogue en cache (indicatif — la vérité reste le backend).
    const products = await offlineGetCachedProducts();
    if (products) {
      (body.items || []).forEach(item => {
        const p = products.find(pp => pp.id === item.product_id);
        if (p) p.stock_quantity = Math.max(0, (p.stock_quantity ?? 0) - item.quantity);
      });
      await cachePut('products', products);
    }
    await renderBanner();
  }

  // ── Moteur de synchronisation ─────────────────────────────────────────────
  async function syncPendingSales() {
    if (_syncing || !navigator.onLine) { await renderBanner(); return; }
    const all = await pendingAll();
    const queue = all.filter(e => e.status === 'pending').sort((a, b) => a.local_id - b.local_id);
    if (!queue.length) { await renderBanner(); return; }

    _syncing = true;
    await renderBanner();
    let synced = 0, failed = 0;

    for (const entry of queue) {
      entry.status = 'syncing';
      await pendingPut(entry);
      await renderBanner();

      let res;
      try {
        res = await fetch(`${API_BASE}/sales`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${localStorage.getItem('token')}`,
          },
          body: JSON.stringify(entry.body),
        });
      } catch (netErr) {
        // Réseau reparti : on s'arrête, reprise au prochain événement online.
        entry.status = 'pending';
        await pendingPut(entry);
        break;
      }

      if (res.status === 201 || res.status === 200) {
        await pendingDelete(entry.local_id);
        await metaPut('last_sync', new Date().toISOString());
        synced++;
      } else if (res.status === 401) {
        // Token expiré : on s'arrête, la file persiste pour après reconnexion.
        entry.status = 'pending';
        await pendingPut(entry);
        break;
      } else {
        let msg = `Erreur ${res.status}`;
        try { const j = await res.json(); msg = j.message || msg; } catch (e) {}
        entry.status = 'failed';
        entry.error = msg;
        await pendingPut(entry);
        failed++;
      }
    }

    _syncing = false;
    await renderBanner();
    if (synced && typeof toast === 'function') toast(`${synced} vente(s) synchronisée(s).`, 'success');
    if (failed && typeof toast === 'function') toast(`${failed} vente(s) à traiter.`, 'warning');
  }

  function offlineSyncNow() { return syncPendingSales(); }

  // ── Résolution des ventes en échec ────────────────────────────────────────
  async function retryFailed(localId) {
    const all = await pendingAll();
    const entry = all.find(e => e.local_id === localId);
    if (!entry) return;
    entry.status = 'pending';
    entry.error = null;
    await pendingPut(entry);
    await refreshFailedOverlay();
    await syncPendingSales();
    await refreshFailedOverlay();
  }

  async function discardFailed(localId) {
    await pendingDelete(localId);
    await refreshFailedOverlay();
    await renderBanner();
  }

  async function refreshFailedOverlay() {
    const list = document.getElementById('offline-failed-list');
    if (!list) return;
    const all = await pendingAll();
    const failed = all.filter(e => e.status === 'failed');
    if (!failed.length) {
      document.getElementById('offline-failed-overlay').classList.add('hidden');
      return;
    }
    list.innerHTML = failed.map(e => {
      const items = (e.display?.items || []).map(i => `${escapeHtml(i.name)} × ${i.qty}`).join(', ');
      const total = e.display?.total ?? 0;
      return `<div class="offline-failed-row">
        <div>
          <div class="offline-failed-title">Vente locale #${e.local_id} — ${total.toLocaleString('fr-FR')} FCFA</div>
          <div class="offline-failed-items">${items}</div>
          <div class="offline-failed-error">${escapeHtml(e.error || 'Échec')}</div>
        </div>
        <div class="offline-failed-actions">
          <button data-retry="${e.local_id}">Réessayer</button>
          <button data-discard="${e.local_id}" class="danger">Abandonner</button>
        </div>
      </div>`;
    }).join('');
    list.querySelectorAll('[data-retry]').forEach(b => b.onclick = () => retryFailed(Number(b.dataset.retry)));
    list.querySelectorAll('[data-discard]').forEach(b => b.onclick = () => discardFailed(Number(b.dataset.discard)));
  }

  async function openFailedOverlay() {
    await refreshFailedOverlay();
    const ov = document.getElementById('offline-failed-overlay');
    const all = await pendingAll();
    if (all.some(e => e.status === 'failed')) ov.classList.remove('hidden');
  }

  function escapeHtml(str) {
    const el = document.createElement('span');
    el.textContent = String(str ?? '');
    return el.innerHTML;
  }

  // ── Bandeau + overlay (DOM injecté une fois) ──────────────────────────────
  function ensureUi() {
    if (document.getElementById('offline-banner')) return;

    const style = document.createElement('style');
    style.textContent = `
      .offline-banner { position: fixed; left: 0; right: 0; bottom: 0; z-index: 3000;
        display: flex; align-items: center; justify-content: center; gap: 12px;
        padding: 8px 16px; font-size: .82rem; font-weight: 600; color: #fff;
        background: var(--accent, #2563EB); box-shadow: 0 -2px 8px rgba(0,0,0,.12); }
      .offline-banner.is-offline { background: var(--warning, #F59E0B); }
      .offline-banner.hidden { display: none; }
      .offline-banner button { border: 1px solid rgba(255,255,255,.6); background: rgba(255,255,255,.15);
        color: #fff; border-radius: 6px; padding: 3px 10px; font-size: .78rem; font-weight: 600;
        cursor: pointer; margin-left: 6px; }
      .offline-banner button:hover { background: rgba(255,255,255,.28); }
      .offline-failed-overlay { position: fixed; inset: 0; z-index: 3100; background: rgba(15,23,42,.45);
        display: flex; align-items: center; justify-content: center; padding: 16px; }
      .offline-failed-overlay.hidden { display: none; }
      .offline-failed-card { background: var(--surface, #fff); border-radius: 14px; max-width: 560px; width: 100%;
        max-height: 85vh; overflow-y: auto; box-shadow: 0 12px 40px rgba(0,0,0,.25); }
      .offline-failed-head { display: flex; align-items: center; justify-content: space-between;
        padding: 16px 20px; border-bottom: 1px solid var(--border-soft, #eee); font-weight: 700; }
      .offline-failed-head button { border: none; background: none; font-size: 1.2rem; cursor: pointer; color: var(--text-muted, #666); }
      .offline-failed-row { display: flex; align-items: center; justify-content: space-between; gap: 12px;
        padding: 12px 20px; border-bottom: 1px solid var(--border-soft, #eee); }
      .offline-failed-title { font-weight: 600; font-size: .9rem; }
      .offline-failed-items { font-size: .8rem; color: var(--text-muted, #666); margin-top: 2px; }
      .offline-failed-error { font-size: .78rem; color: var(--danger, #DC2626); margin-top: 4px; }
      .offline-failed-actions { display: flex; gap: 6px; flex-shrink: 0; }
      .offline-failed-actions button { border: 1px solid var(--border, #ddd); background: var(--surface, #fff);
        border-radius: 6px; padding: 5px 10px; font-size: .78rem; font-weight: 600; cursor: pointer; }
      .offline-failed-actions button.danger { color: var(--danger, #DC2626); border-color: var(--danger, #DC2626); }
    `;
    document.head.appendChild(style);

    const banner = document.createElement('div');
    banner.id = 'offline-banner';
    banner.className = 'offline-banner hidden';
    banner.innerHTML = '<span id="offline-banner-msg"></span><span id="offline-banner-actions"></span>';
    document.body.appendChild(banner);

    const overlay = document.createElement('div');
    overlay.id = 'offline-failed-overlay';
    overlay.className = 'offline-failed-overlay hidden';
    overlay.innerHTML = `<div class="offline-failed-card">
      <div class="offline-failed-head"><span>Ventes à traiter</span><button id="offline-failed-close">&times;</button></div>
      <div id="offline-failed-list"></div>
    </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.classList.add('hidden'); });
    document.getElementById('offline-failed-close').onclick = () => overlay.classList.add('hidden');
  }

  async function renderBanner() {
    ensureUi();
    const all = await pendingAll();
    const pending = all.filter(e => e.status === 'pending' || e.status === 'syncing').length;
    const failed = all.filter(e => e.status === 'failed').length;
    const online = navigator.onLine;

    const banner = document.getElementById('offline-banner');
    const msg = document.getElementById('offline-banner-msg');
    const actions = document.getElementById('offline-banner-actions');

    if (online && pending === 0 && failed === 0) { banner.classList.add('hidden'); return; }

    banner.className = 'offline-banner';
    if (!online) banner.classList.add('is-offline');
    actions.innerHTML = '';

    const parts = [];
    if (!online) parts.push('Hors ligne — les ventes seront synchronisées au retour du réseau');
    if (_syncing) parts.push('Synchronisation…');
    else if (pending > 0) parts.push(`${pending} vente(s) en attente`);
    if (failed > 0) parts.push(`${failed} à traiter`);
    msg.textContent = parts.join(' · ');

    if (pending > 0 && online && !_syncing) {
      const b = document.createElement('button');
      b.textContent = 'Synchroniser';
      b.onclick = () => syncPendingSales();
      actions.appendChild(b);
    }
    if (failed > 0) {
      const b = document.createElement('button');
      b.textContent = 'Traiter';
      b.onclick = openFailedOverlay;
      actions.appendChild(b);
    }
  }

  // ── Initialisation ────────────────────────────────────────────────────────
  function init() {
    ensureUi();
    renderBanner();
    window.addEventListener('online', () => { renderBanner(); syncPendingSales(); });
    window.addEventListener('offline', () => renderBanner());
  }

  // Exposition publique
  window.offlineIsOnline = offlineIsOnline;
  window.offlineCacheCatalog = offlineCacheCatalog;
  window.offlineGetCachedProducts = offlineGetCachedProducts;
  window.offlineGetCachedCategories = offlineGetCachedCategories;
  window.offlineGetCachedSession = offlineGetCachedSession;
  window.offlineSaveSale = offlineSaveSale;
  window.offlineSyncNow = offlineSyncNow;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
```

- [ ] **Step 2: Charger `offline.js` sur les 11 pages**

Dans **chacun** de `frontend/dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html`, `profile.html`, `settings.html`, `vente-tablette.html`, `login.html`, ajouter la ligne `<script src="js/offline.js"></script>` **juste après** la ligne `<script src="js/app.js"></script>` existante. (Sur chaque page, `js/app.js` est présent une seule fois — insérer juste après.)

- [ ] **Step 3: Vérification statique**

1. `node --check frontend/js/offline.js` → aucune erreur de syntaxe.
2. `grep -c "js/offline.js" frontend/dashboard.html frontend/products.html frontend/stock.html frontend/inventory-count.html frontend/pos.html frontend/reports.html frontend/users.html frontend/profile.html frontend/settings.html frontend/vente-tablette.html frontend/login.html` → `1` pour chacun.

- [ ] **Step 4: Vérification navigateur (module seul, sans encaissement)**

1. `cd backend && php artisan serve --port=8000` + `cd frontend && php -S localhost:3000`.
2. Ouvrir `http://localhost:3000/dashboard.html`, se connecter (`admin`/`admin123`).
3. DevTools → Application → IndexedDB : la base `boutique-d-offline` existe avec les 3 stores `cache`, `pending_sales`, `meta`.
4. DevTools → Network → passer en « Offline » : le bandeau orange « Hors ligne… » apparaît en bas. Repasser en « No throttling » (online) : le bandeau se masque (file vide).
5. Console : `await window.offlineSaveSale({sale_type:'detail',payment_method:'especes',amount_paid:0,items:[]}, {items:[{name:'Test',qty:1,total:500}],total:500})` puis vérifier le bandeau bleu « 1 vente(s) en attente · [Synchroniser] ». Ne PAS cliquer Synchroniser (créerait une vraie vente vide qui échouerait) — supprimer l'entrée de test : DevTools → IndexedDB → `pending_sales` → clic droit → Delete, puis recharger.

- [ ] **Step 5: Commit**

```bash
git add frontend/js/offline.js frontend/dashboard.html frontend/products.html frontend/stock.html frontend/inventory-count.html frontend/pos.html frontend/reports.html frontend/users.html frontend/profile.html frontend/settings.html frontend/vente-tablette.html frontend/login.html
git commit -m "feat: module offline.js (IndexedDB, bandeau connectivite, moteur de synchro) charge sur toutes les pages"
```

---

### Task 3: Frontend — intégration hors-ligne dans `pos.html`

**Files:**
- Modify: `frontend/pos.html`

**Interfaces:**
- Consumes: l'API `window.offline*` (Task 2), les fonctions existantes de `pos.html` (`loadProducts`, `loadSession`, `loadCategories`, `renderProducts`, `renderCart`, `processSale`, `openModal`/`closeModal`, `currentUser`, `fmt`, `escHtml`, `toast`, `refreshIcons`), les globales `allProducts`, `cart`, `discount`, `session`, `pendingSaleId`.
- Produces: rien (page terminale). Le POS encaisse hors-ligne et alimente la file gérée par `offline.js`.

- [ ] **Step 1: Mise en cache du catalogue au chargement + repli hors-ligne — `loadProducts()`**

Remplacer la fonction `loadProducts()` existante par :
```javascript
async function loadProducts() {
  const grid = document.getElementById('products-grid');
  if (!grid.children.length || ![...grid.children].some(el => !el.classList.contains('skeleton'))) {
    grid.innerHTML = Array(8).fill('<div class="skeleton" style="height:92px;border-radius:12px"></div>').join('');
  }
  if (navigator.onLine) {
    try {
      allProducts = await api.get('/products');
      renderProducts(allProducts);
      window.offlineCacheCatalog({ products: allProducts });
      return;
    } catch (err) {
      // Réseau tombé entre-temps : bascule sur le cache ci-dessous.
    }
  }
  const cached = await window.offlineGetCachedProducts();
  if (cached && cached.length) {
    allProducts = cached;
    renderProducts(allProducts);
  } else {
    allProducts = [];
    toast('Catalogue indisponible hors ligne. Ouvrez la caisse une fois en ligne pour le mettre en cache.', 'warning');
    grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><div class="icon-wrap"><i data-lucide="wifi-off" class="icon"></i></div><h4>Catalogue indisponible hors ligne</h4></div>';
    refreshIcons();
  }
}
```

- [ ] **Step 2: Session depuis le cache hors-ligne — `loadSession()`**

Remplacer la fonction `loadSession()` existante par :
```javascript
async function loadSession() {
  if (navigator.onLine) {
    try {
      session = await api.get('/cash-sessions/current');
      window.offlineCacheCatalog({ session });
    } catch {
      session = null;
      window.offlineCacheCatalog({ session: null });
    }
  } else {
    session = await window.offlineGetCachedSession();
  }
  updateSessionBanner();
}
```

- [ ] **Step 3: Mise en cache des catégories — `loadCategories()`**

Remplacer la fonction `loadCategories()` existante par :
```javascript
async function loadCategories() {
  const sel = document.getElementById('cat-filter');
  let cats = null;
  if (navigator.onLine) {
    try { cats = await api.get('/categories'); window.offlineCacheCatalog({ categories: cats }); } catch {}
  }
  if (!cats) cats = await window.offlineGetCachedCategories();
  (cats || []).forEach(c => {
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name;
    sel.appendChild(opt);
  });
}
```

- [ ] **Step 4: Verrouillage du paiement hors-ligne**

Ajouter cette fonction dans le script inline (par exemple juste avant `function bindEvents()`) :
```javascript
function updateOfflinePaymentUI() {
  const sel = document.getElementById('payment-method');
  const mm  = sel.querySelector('option[value="mobile_money"]');
  if (!navigator.onLine) {
    sel.value = 'especes';
    mm.disabled = true;
    mm.textContent = 'Mobile Money (indisponible hors ligne)';
  } else {
    mm.disabled = false;
    mm.textContent = 'Mobile Money';
  }
}
```

Dans `bindEvents()`, à la fin de la fonction, ajouter :
```javascript
  window.addEventListener('online', updateOfflinePaymentUI);
  window.addEventListener('offline', updateOfflinePaymentUI);
  updateOfflinePaymentUI();
```

- [ ] **Step 5: Chemin hors-ligne dans `processSale()`**

Au tout début de `processSale(total, subtotal, remise, method, saleType, amountPaid, mobileNumber)`, **avant** la ligne `const btn = document.getElementById('btn-confirm-pay');`, insérer le branchement hors-ligne :
```javascript
  if (!navigator.onLine) {
    if (pendingSaleId) { toast('La validation d\'un panier vendeur nécessite une connexion.', 'warning'); return; }
    const btnOff = document.getElementById('btn-confirm-pay');
    btnOff.disabled = true;
    const items = cart.map(i => ({ product_id: i.product.id, quantity: i.qty }));
    const body = {
      sale_type:   saleType,
      payment_method: 'especes',
      amount_paid: amountPaid ?? total,
      items,
      ...(discount.type ? { discount_type: discount.type, discount_value: discount.value } : {}),
    };
    const display = { items: cart.map(i => ({ name: i.product.name, qty: i.qty, total: i.qty * i.unitPrice })), total };
    const syntheticSale = {
      items: cart.map(i => ({ product: i.product.name, quantity: i.qty, total: i.qty * i.unitPrice })),
      receipt_number: 'HORS-LIGNE — à synchroniser',
      date: new Date().toLocaleString('fr-FR'),
      cashier: currentUser().name,
      subtotal, discount_value: remise, total,
      payment_method: 'especes', amount_paid: amountPaid ?? total,
      change_given: Math.max(0, (amountPaid ?? total) - total),
    };
    try {
      await window.offlineSaveSale(body, display);
      closeModal('modal-payment');
      showOfflineReceipt(syntheticSale);
      cart = [];
      discount = { type: null, value: 0 };
      renderCart();
      await loadProducts();
    } catch (err) {
      toast('Impossible d\'enregistrer la vente hors ligne : ' + err.message, 'danger');
      btnOff.disabled = false;
    }
    return;
  }
```

- [ ] **Step 6: Reçu hors-ligne — `showOfflineReceipt()`**

Ajouter cette fonction juste après la fonction `showReceipt()` existante (elle réutilise le même markup de reçu, sans le bouton PDF qui nécessite le serveur, et avec une mention « à synchroniser ») :
```javascript
function showOfflineReceipt(sale) {
  const lines = (sale.items || []).map(i =>
    `<div class="receipt-row"><span>${escHtml(i.product)} × ${i.quantity}</span><span>${fmt(i.total)}</span></div>`
  ).join('');

  document.getElementById('payment-title').innerHTML = '<i data-lucide="receipt" class="icon"></i> Reçu (hors ligne)';
  document.getElementById('payment-body').innerHTML = `
    <div class="alert alert-warning mb-4"><i data-lucide="cloud-off" class="icon"></i>
      <div>Vente enregistrée hors ligne — elle sera synchronisée au retour du réseau.</div></div>
    <div class="receipt">
      <h3><i data-lucide="shopping-bag" class="icon"></i> Boutique D</h3>
      <div class="receipt-sub">${escHtml(sale.date ?? '')}</div>
      <div class="receipt-sub">${escHtml(sale.receipt_number ?? '')}</div>
      <div class="receipt-sub">Caissier : ${escHtml(sale.cashier ?? '—')}</div>
      <hr>
      ${lines}
      <hr>
      <div class="receipt-row"><span>Sous-total</span><span>${fmt(sale.subtotal)}</span></div>
      ${sale.discount_value > 0 ? `<div class="receipt-row"><span>Remise</span><span>- ${fmt(sale.discount_value)}</span></div>` : ''}
      <div class="receipt-row receipt-total"><span>TOTAL</span><span>${fmt(sale.total)}</span></div>
      <hr>
      <div class="receipt-row"><span>Paiement</span><span>Espèces</span></div>
      ${sale.amount_paid ? `<div class="receipt-row"><span>Reçu</span><span>${fmt(sale.amount_paid)}</span></div>` : ''}
      ${sale.change_given > 0 ? `<div class="receipt-row"><span>Monnaie rendue</span><span>${fmt(sale.change_given)}</span></div>` : ''}
      <div class="receipt-footer">Merci pour votre achat !</div>
    </div>`;
  document.getElementById('payment-footer').innerHTML = `
    <button class="btn btn-primary btn-block" data-dismiss>
      <i data-lucide="check" class="icon"></i> Fermer
    </button>`;
  openModal('modal-payment');
  refreshIcons();
  document.querySelectorAll('#modal-payment [data-dismiss]').forEach(b =>
    b.addEventListener('click', () => closeModal('modal-payment')));
}
```

- [ ] **Step 7: Vérification statique**

1. Extraire le JS inline de `pos.html` et lancer `node --check` dessus (ou vérifier via un outil équivalent) → aucune erreur de syntaxe.
2. `grep -n "showOfflineReceipt\|offlineSaveSale\|updateOfflinePaymentUI" frontend/pos.html` → chaque nom apparaît à sa définition et à son usage.

- [ ] **Step 8: Vérification navigateur — bout en bout**

1. Serveurs démarrés, `admin`/`admin123`, ouvrir `pos.html` **en ligne** (pour peupler le cache), ouvrir la caisse si besoin.
2. DevTools → Network → Offline. Le bandeau orange apparaît ; le sélecteur de paiement force « Espèces », l'option Mobile Money est grisée.
3. Ajouter un produit au panier, Encaisser → confirmer → reçu « hors ligne — à synchroniser », panier vidé, stock du produit dans la grille décrémenté.
4. DevTools → IndexedDB → `pending_sales` : une entrée `status:'pending'`. Bandeau bleu « 1 vente(s) en attente ».
5. Repasser en ligne (No throttling) → synchro automatique → toast « 1 vente(s) synchronisée(s) », bandeau masqué, entrée `pending_sales` disparue.
6. Vérifier côté serveur (page Rapports > Ventes, ou `GET /api/sales`) que la vente a bien été créée avec le bon total.

- [ ] **Step 9: Commit**

```bash
git add frontend/pos.html
git commit -m "feat: encaissement hors-ligne au POS (file IndexedDB, recu hors-ligne, synchro auto au retour reseau)"
```

---

### Task 4: Vérification finale du module

**Files:** aucun changement de code — vérification uniquement (sauf correctif éventuel).

- [ ] **Step 1: Suite complète**

Run: `cd backend && php artisan test` — Attendu : tous PASS (50 tests, sortie propre).

- [ ] **Step 2: Non-régression vente en ligne classique**

Sur `pos.html` **en ligne**, faire une vente espèces normale + une vente mobile money : les deux fonctionnent comme avant (le champ `uuid` n'est pas envoyé par le chemin en ligne, `store()` crée normalement). Vérifier que `SaleController::store()` n'a été modifié que de façon additive (`git diff master -- backend/app/Http/Controllers/Api/SaleController.php` ne montre que l'ajout de la règle `uuid`, du bloc de dédup et du champ `sync_uuid` — aucune ligne existante supprimée hors ces insertions).

- [ ] **Step 3: Scénario de conflit de stock (résolution manuelle)**

1. En ligne, noter le stock d'un produit (ex. 10). Passer hors-ligne, encaisser 8 unités de ce produit → vente en file, stock local affiché ~2.
2. Rester hors-ligne. Dans un autre onglet **en ligne** (ou via `curl` authentifié), vendre 5 unités du même produit en ligne → stock backend passe à 5.
3. Repasser le premier onglet en ligne → la synchro rejoue la vente de 8 : le backend refuse (stock 5 < 8) → la vente passe `failed`, toast « 1 vente à traiter », segment rouge « 1 à traiter · [Traiter] ».
4. Cliquer « Traiter » → l'overlay liste la vente avec le message backend « Stock insuffisant… ». Réapprovisionner le produit (page Stock, +10), puis « Réessayer » → la vente se synchronise, disparaît de la file.
5. Refaire le scénario mais cliquer « Abandonner » → la vente disparaît sans être créée en base.

- [ ] **Step 4: Idempotence (dédup UUID)**

1. Créer une vente hors-ligne. Avant de repasser en ligne, DevTools → IndexedDB → `pending_sales` → noter le `uuid` de l'entrée.
2. Repasser en ligne → synchro → vente créée. Vérifier via `GET /api/sales` qu'une seule vente porte ce total.
3. Simuler un rejeu : Console → `fetch(API_BASE+'/sales', {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+localStorage.getItem('token')}, body: JSON.stringify(<le body de l'entrée, avec le même uuid>)}).then(r=>r.status)` → renvoie `200` (pas 201), et le nombre de ventes en base est inchangé.

- [ ] **Step 5: Commit (si correctif)**

Si une anomalie est trouvée et corrigée pendant cette vérification, commit dédié. Sinon, rien à commit — cette tâche clôt le module avant la revue finale de branche.
