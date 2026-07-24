# Phase A — Refonte design enrichie — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Terminer la refonte design des 8 pages restantes avec le design system validé, enrichi de touches signature (skeleton loaders, états vides illustrés, sparkline KPI, micro-interactions), sans modifier aucune logique métier, API ou route.

**Architecture:** Ce plan **orchestre** le plan détaillé existant `docs/superpowers/plans/2026-07-13-refonte-design-ui.md` (Tasks 5–13, code complet, à exécuter telles quelles) et y ajoute : une tâche préalable A0 d'enrichissement du design system (CSS + helpers JS partagés), puis pour chaque page un court bloc d'enrichissement (skeletons + états vides via helpers centraux). L'astuce DRY centrale : `renderTable()` (déjà appelée par toutes les pages) est upgradée UNE fois pour rendre les états vides illustrés partout, et `showTableSkeleton()` s'insère en une ligne par fonction de chargement.

**Tech Stack:** HTML/CSS/JS vanilla, Inter, Lucide 0.462.0 (CDN épinglé + SRI), aucun build step, aucun framework de test frontend (vérifications manuelles navigateur + console documentées par étape).

## Global Constraints

- Aucune modification de logique métier, d'API, de route Laravel, de structure de données (spec 2026-07-24 §Phase A ; spec 2026-07-13 §Contraintes).
- Tous les sélecteurs consommés par `js/app.js` et le JS inline des pages sont préservés (`.card`, `.kpi-card`, `#topbar-user`, `renderTable`, etc.).
- Icônes : Lucide `0.462.0` épinglé, script CDN avec `integrity="sha384-WBRt9V/J/erVtkEuP91HUFRv9MvHzFiFOp4/zTDp4xkcMG7aOeIv2asTV4yxFLWa" crossorigin="anonymous"` — jamais Bootstrap Icons dans le nouveau code, jamais de montée de version sans recalcul du hash.
- Responsive identique sur toutes les pages : <1140px, <900px (rail 68px), <560px.
- Pas d'animation de contenu au chargement initial (spec) — les skeletons remplacent le vide, ils ne « font pas d'entrée ».
- Déploiement page par page : chaque tâche = une page livrée, vérifiée sans régression, commitée, **validée par le client avant la suivante**.
- Serveurs de test : `php artisan serve --port=8000` (depuis `backend/`) + `php -S localhost:3000` (depuis `frontend/`). Compte de test : `admin` / `admin123`.
- Le plan de référence pour le détail des pages est `docs/superpowers/plans/2026-07-13-refonte-design-ui.md` — abrégé **[PLAN-13/07]** ci-dessous. Ses Tasks 1, 2 et 4 sont **déjà livrées** (commits `eb3c028`, `cf65488`, `7c2a630`).

## File Structure

- **Modify:** `frontend/css/app.css` — ajouts d'enrichissement uniquement (Task A0), aucun sélecteur existant supprimé.
- **Modify:** `frontend/js/app.js` — upgrade de `renderTable()` (état vide illustré) + nouveaux helpers `showTableSkeleton()`, `sparklineSvg()`.
- **Modify:** `frontend/dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html` — selon [PLAN-13/07] Tasks 5–11 + blocs d'enrichissement ci-dessous.
- **Create:** `frontend/profile.html` — selon [PLAN-13/07] Task 12.
- **No changes:** `frontend/js/api.js`, tout `backend/`, `manifest.json`, `sw.js`.

---

### Task A0: Enrichissements du design system (CSS + helpers JS)

**Files:**
- Modify: `frontend/css/app.css` (ajouts en fin de fichier, avant le bloc `/* ── Responsive ── */`)
- Modify: `frontend/js/app.js`

**Interfaces:**
- Consumes: tokens CSS existants (`--border-soft`, `--accent`, `--text-subtle`…), `refreshIcons()` existant.
- Produces (utilisés par Tasks A1–A8) :
  - `showTableSkeleton(tbodyId, rows = 4)` — remplit un `<tbody>` de lignes shimmer (colonnes auto-détectées via les `<th>`), à appeler juste avant chaque `api.get` qui alimente un tableau.
  - `renderTable(tbodyId, rows, emptyMsg, emptyIcon = 'inbox')` — signature étendue (4e paramètre optionnel) ; cas vide = état vide illustré centralisé. Rétro-compatible : tous les appels existants à 3 arguments restent valides.
  - `sparklineSvg(values, opts = {})` — retourne une string SVG (ligne + aire dégradée) ; `opts = {width=140, height=38, stroke='var(--accent)'}`. Retourne `''` si moins de 2 points.

- [ ] **Step 1: Ajouts CSS dans `frontend/css/app.css`**

Insérer avant le bloc `/* ── Responsive ── */` :

```css
/* ── Enrichissements (Phase A0) ── */
.tnum { font-variant-numeric: tabular-nums; }
td.text-right, .kpi-sub { font-variant-numeric: tabular-nums; }

.btn:active:not(:disabled) { transform: translateY(0.5px) scale(.99); }
:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 4px; }
.form-control:focus-visible { outline: none; } /* le ring box-shadow existant suffit */

.skeleton-line {
  height: 12px;
  border-radius: 6px;
  background: linear-gradient(90deg, var(--border-soft) 25%, #E9EDF3 50%, var(--border-soft) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.4s ease infinite;
}
tr.skeleton-row td { padding: 15px 20px; }
tr.skeleton-row:hover td { background: transparent; }

.empty-state h4 { font-size: .9rem; font-weight: 600; color: var(--text-muted); }

.kpi-spark { margin-top: 6px; line-height: 0; }
.kpi-spark svg { display: block; overflow: visible; }
```

- [ ] **Step 2: Helpers dans `frontend/js/app.js`**

Ajouter après la fonction `refreshIcons()` :

```javascript
// ── Skeleton loader pour tableaux ────────────────────────────────────────────
function showTableSkeleton(tbodyId, rows = 4) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  const cols = tbody.closest('table')?.querySelectorAll('th').length || 4;
  const widths = ['70%', '45%', '85%', '55%', '65%', '40%'];
  let html = '';
  for (let r = 0; r < rows; r++) {
    html += '<tr class="skeleton-row">';
    for (let c = 0; c < cols; c++) {
      html += `<td><div class="skeleton-line" style="width:${widths[(r + c) % widths.length]}"></div></td>`;
    }
    html += '</tr>';
  }
  tbody.innerHTML = html;
}

// ── Sparkline SVG ────────────────────────────────────────────────────────────
function sparklineSvg(values, opts = {}) {
  if (!Array.isArray(values) || values.length < 2) return '';
  const w = opts.width ?? 140, h = opts.height ?? 38, stroke = opts.stroke ?? 'var(--accent)';
  const max = Math.max(...values), min = Math.min(...values);
  const range = max - min || 1;
  const pts = values.map((v, i) => [
    (i / (values.length - 1)) * w,
    h - 3 - ((v - min) / range) * (h - 6),
  ]);
  const line = pts.map(p => `${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(' ');
  const area = `0,${h} ${line} ${w},${h}`;
  return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" aria-hidden="true">
    <defs><linearGradient id="sparkfill" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#2563EB" stop-opacity=".18"/>
      <stop offset="100%" stop-color="#2563EB" stop-opacity="0"/>
    </linearGradient></defs>
    <polygon points="${area}" fill="url(#sparkfill)"/>
    <polyline points="${line}" fill="none" stroke="${stroke}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>`;
}
```

- [ ] **Step 3: Upgrade de `renderTable()` (état vide illustré centralisé)**

Remplacer la fonction `renderTable` existante par :

```javascript
// ── Generic table renderer ────────────────────────────────────────────────────
function renderTable(tbodyId, rows, emptyMsg = 'Aucune donnée', emptyIcon = 'inbox') {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!rows || rows.length === 0) {
    const cols = tbody.closest('table')?.querySelectorAll('th').length || 4;
    tbody.innerHTML = `<tr><td colspan="${cols}" style="padding:0">
      <div class="empty-state">
        <div class="icon-wrap"><i data-lucide="${emptyIcon}" class="icon"></i></div>
        <h4>${emptyMsg}</h4>
      </div>
    </td></tr>`;
    refreshIcons();
    return;
  }
  tbody.innerHTML = rows.join('');
  refreshIcons();
}
```

(`emptyMsg` provient toujours du code de la page, jamais d'une saisie utilisateur — pas de risque XSS nouveau ; `escHtml` reste disponible si un message dynamique apparaît un jour.)

- [ ] **Step 4: Vérification manuelle**

1. Lancer les deux serveurs, se connecter (`admin`/`admin123`).
2. Console navigateur (F12) sur n'importe quelle page :
   - `sparklineSvg([1,4,2,8,5])` → retourne une string commençant par `<svg width="140"`.
   - `sparklineSvg([3])` → retourne `''`.
   - `showTableSkeleton('top5-tbody')` (sur dashboard.html) → le tableau affiche 4 lignes shimmer animées.
   - `renderTable('top5-tbody', [], 'Test vide', 'shopping-cart')` → état vide avec icône panier + texte « Test vide ».
3. Vérifier qu'aucune page existante n'est cassée : dashboard s'affiche, tableaux remplis normalement après rechargement.

- [ ] **Step 5: Commit**

```bash
git add frontend/css/app.css frontend/js/app.js
git commit -m "design: enrichissements design system (skeletons, etats vides illustres, sparkline, micro-interactions)"
```

---

## Tasks A1–A8 — pages, une par une

Chaque tâche ci-dessous = **exécuter la task correspondante de [PLAN-13/07] telle qu'écrite** (elle contient le markup, les mappings d'icônes et les vérifications complets), **puis appliquer le bloc d'enrichissement** indiqué, **puis** la vérification et le commit de [PLAN-13/07] (un seul commit par page, enrichissements inclus). Ne pas passer à la page suivante sans validation du client.

Motif d'enrichissement commun (référencé ci-dessous comme **[SKEL]**) : dans chaque fonction de chargement listée, insérer `showTableSkeleton('<tbody-id>');` **en première ligne**, avant le `await api.get(...)`. Le `renderTable(...)` en fin de fonction remplace naturellement le skeleton. En cas d'erreur API (bloc `catch` existant), appeler `renderTable('<tbody-id>', [], 'Erreur de chargement', 'triangle-alert')`.

### Task A1: Dashboard

**Files:** Modify: `frontend/dashboard.html`

- [ ] **Step 1:** Exécuter [PLAN-13/07] **Task 5** (lignes 1024–1148) intégralement.
- [ ] **Step 2 [SKEL]:** dans `loadDashboard()` : `showTableSkeleton('top5-tbody', 5);` en première ligne. État vide : `renderTable('top5-tbody', top5Rows, 'Aucune vente aujourd\'hui', 'shopping-cart')`.
- [ ] **Step 3: Sparkline CA 7 jours sur la carte KPI vedette.** Dans le markup de la carte KPI « CA du jour » (carte `.kpi-card.primary` posée au Step 1), ajouter sous le `.kpi-sub` : `<div class="kpi-spark" id="kpi-spark"></div>`. Puis ajouter à la fin du script inline (et l'appeler depuis `loadDashboard()` après le rendu des KPI) :

```javascript
async function loadSparkline() {
  try {
    const end = new Date();
    const start = new Date(); start.setDate(end.getDate() - 6);
    const iso = d => d.toISOString().slice(0, 10);
    const data = await api.get(`/reports/sales?period=custom&start_date=${iso(start)}&end_date=${iso(end)}`);
    const byDay = {};
    for (let i = 0; i < 7; i++) {
      const d = new Date(start); d.setDate(start.getDate() + i);
      byDay[d.toLocaleDateString('fr-FR')] = 0; // clé "jj/mm/aaaa"
    }
    (data.ventes || []).forEach(v => {
      const day = v.date.split(' ')[0]; // "jj/mm/aaaa hh:mm" → "jj/mm/aaaa"
      if (day in byDay) byDay[day] += v.total;
    });
    const el = document.getElementById('kpi-spark');
    if (el) el.innerHTML = sparklineSvg(Object.values(byDay));
  } catch { /* silencieux : le rôle sans accès rapports ou une erreur réseau masque simplement la sparkline */ }
}
```

(Endpoint existant `GET /reports/sales`, aucun changement backend. Le `catch` silencieux couvre le cas gestionnaire/403.)
- [ ] **Step 4:** Vérifications de [PLAN-13/07] Task 5 + : recharger la page → lignes shimmer visibles brièvement dans « Top 5 » ; sparkline visible sur la carte CA du jour (avec des ventes en base) ; couper le backend et recharger → état vide « Erreur de chargement » avec icône alerte, pas de page cassée.
- [ ] **Step 5:** Commit : `git add frontend/dashboard.html && git commit -m "design: applique le design system enrichi au dashboard"`

### Task A2: Produits

**Files:** Modify: `frontend/products.html`

- [ ] **Step 1:** Exécuter [PLAN-13/07] **Task 6** (lignes 1149–1256) intégralement.
- [ ] **Step 2 [SKEL]:** `loadCategories()` → `showTableSkeleton('cats-tbody', 3);` ; `loadProducts()` → `showTableSkeleton('products-tbody', 6);`. États vides : catégories `('cats-tbody', …, 'Aucune catégorie', 'folder-open')`, produits `('products-tbody', …, 'Aucun produit', 'package')`.
- [ ] **Step 3:** Vérifications [PLAN-13/07] Task 6 + skeletons visibles au chargement + recherche produit sans résultat → état vide illustré.
- [ ] **Step 4:** Commit : `git add frontend/products.html && git commit -m "design: applique le design system enrichi a la page produits"`

### Task A3: Stock

**Files:** Modify: `frontend/stock.html`

- [ ] **Step 1:** Exécuter [PLAN-13/07] **Task 7** (lignes 1257–1353) intégralement.
- [ ] **Step 2 [SKEL]:** `loadStockTab()` → `showTableSkeleton('stock-tbody', 6);` ; `loadEntriesTab()` → `('entries-tbody', 4)` ; `loadExitsTab()` → `('exits-tbody', 4)` ; `loadInventoriesTab()` → `('inventories-tbody', 3)`. États vides : stock `'Aucun produit en stock', 'boxes'` ; entrées `'Aucune entrée', 'package-plus'` ; sorties `'Aucune sortie', 'package-minus'` ; inventaires `'Aucun inventaire', 'clipboard-check'`.
- [ ] **Step 3:** Vérifications [PLAN-13/07] Task 7 + skeleton à chaque changement d'onglet.
- [ ] **Step 4:** Commit : `git add frontend/stock.html && git commit -m "design: applique le design system enrichi a la page stock"`

### Task A4: Inventaire (comptage)

**Files:** Modify: `frontend/inventory-count.html`

- [ ] **Step 1:** Exécuter [PLAN-13/07] **Task 8** (lignes 1354–1425) intégralement.
- [ ] **Step 2 [SKEL]:** `loadInventory()` → `showTableSkeleton('inv-items-tbody', 6);` en première ligne. État vide : `'Aucun article', 'clipboard-check'`.
- [ ] **Step 3:** Vérifications [PLAN-13/07] Task 8 (workflow comptage complet : saisie, écarts, validation).
- [ ] **Step 4:** Commit : `git add frontend/inventory-count.html && git commit -m "design: applique le design system enrichi a la page inventaire"`

### Task A5: Point de vente (POS)

**Files:** Modify: `frontend/pos.html`

- [ ] **Step 1:** Exécuter [PLAN-13/07] **Task 9** (lignes 1426–1577) intégralement.
- [ ] **Step 2:** Pas de `renderTable` sur cette page (grille produits custom) — enrichissement : pendant `loadProducts()`, afficher dans le conteneur de la grille produits 8 tuiles `<div class="skeleton" style="height:92px;border-radius:12px"></div>` (dans la grille existante) avant le `await`, remplacées par le rendu normal. Panier vide → utiliser le composant `.empty-state` (icône `shopping-cart`, « Panier vide », sous-texte « Scannez ou cliquez un produit »).
- [ ] **Step 3:** Vérifications [PLAN-13/07] Task 9 (vente complète espèces + mobile money, remise, session caisse) — **la page la plus critique : dérouler TOUTES les vérifications**.
- [ ] **Step 4:** Commit : `git add frontend/pos.html && git commit -m "design: applique le design system enrichi au point de vente"`

### Task A6: Rapports

**Files:** Modify: `frontend/reports.html`

- [ ] **Step 1:** Exécuter [PLAN-13/07] **Task 10** (lignes 1578–1738) intégralement.
- [ ] **Step 2 [SKEL]:** `loadSales()` → `showTableSkeleton('sales-tbody', 6);` ; `loadTreasury()` → `('treasury-tbody', 4)` ; `loadEmployees()` → `('employees-tbody', 4)`. États vides : ventes `'Aucune vente sur la période', 'chart-column'` ; trésorerie `'Aucune session de caisse', 'wallet'` ; employés `'Aucune donnée employé', 'users'`.
- [ ] **Step 3:** Vérifications [PLAN-13/07] Task 10 + skeletons au changement de période/filtre.
- [ ] **Step 4:** Commit : `git add frontend/reports.html && git commit -m "design: applique le design system enrichi a la page rapports"`

### Task A7: Utilisateurs

**Files:** Modify: `frontend/users.html`

- [ ] **Step 1:** Exécuter [PLAN-13/07] **Task 11** (lignes 1739–1825) intégralement.
- [ ] **Step 2 [SKEL]:** `loadUsers()` → `showTableSkeleton('users-tbody', 4);`. État vide : `'Aucun utilisateur', 'users'`.
- [ ] **Step 3:** Vérifications [PLAN-13/07] Task 11 (création, modification, activation/désactivation d'utilisateur).
- [ ] **Step 4:** Commit : `git add frontend/users.html && git commit -m "design: applique le design system enrichi a la page utilisateurs"`

### Task A8: Profil (nouvelle page)

**Files:** Create: `frontend/profile.html`

- [ ] **Step 1:** Exécuter [PLAN-13/07] **Task 12** (lignes 1826–2025) intégralement — la page y est fournie en entier (infos compte + changement de mot de passe via `PUT /auth/password`).
- [ ] **Step 2:** Vérifications [PLAN-13/07] Task 12 : affichage des infos, changement de mot de passe réussi (reconnexion avec le nouveau), erreur sur ancien mot de passe incorrect.
- [ ] **Step 3:** Commit : `git add frontend/profile.html && git commit -m "design: nouvelle page profil (infos compte + mot de passe)"`

### Task A9: Harmonisation finale

**Files:** Modify: les 9 pages + `frontend/css/app.css` si besoin

- [ ] **Step 1:** Exécuter [PLAN-13/07] **Task 13** (lignes 2026–fin) : cohérence marges/ombres/hauteurs/états, responsive 1140/900/560 sur chaque page, `grep -rn "bi bi-" frontend/` doit retourner **zéro** résultat.
- [ ] **Step 2:** Vérifications d'enrichissement transverses : chaque tableau de l'app affiche un skeleton au chargement et un état vide illustré quand il est vide ; tous les montants sont en chiffres tabulaires (colonnes alignées) ; `:focus-visible` visible au clavier (Tab) sur boutons/liens/inputs de chaque page.
- [ ] **Step 3:** Commit : `git add -A frontend/ && git commit -m "design: passe finale d'harmonisation sur les 9 pages"`
- [ ] **Step 4:** Après validation client : merge dans master.

```bash
git checkout master
git merge --no-ff design/refonte-ui -m "design: refonte UI complete (design system enrichi, 9 pages)"
```
