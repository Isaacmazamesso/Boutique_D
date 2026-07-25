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
