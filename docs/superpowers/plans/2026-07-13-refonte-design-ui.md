# Refonte design UI — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer le design actuel (indigo/violet/amber, Bootstrap Icons) par le design system "SaaS premium" validé par le client (fond blanc dominant, palette slate/bleu, icônes Lucide), appliqué aux 8 pages du frontend, sans modifier aucune logique métier, API, route ou structure de données.

**Architecture:** Le frontend est du HTML/CSS/JS vanilla, une page par fichier, un stylesheet global partagé (`frontend/css/app.css`) et deux scripts JS partagés (`frontend/js/api.js`, `frontend/js/app.js`). La refonte réutilise EXACTEMENT les mêmes noms de classes/id déjà consommés par `js/app.js` et par le JS inline de chaque page (`.card`, `.btn-primary`, `.badge-success`, `.kpi-card`, `.nav-item`, `.modal-backdrop`, `#topbar-user`, etc.) — seules les valeurs CSS et les icônes changent. Le remplacement d'icônes (Bootstrap Icons → Lucide) est le seul changement de markup systématique ; il touche `<i class="bi bi-xxx">` → `<i data-lucide="xxx" class="icon"></i>` partout, plus un appel `lucide.createIcons()` centralisé dans `app.js`.

**Tech Stack:** HTML/CSS/JS vanilla, police Inter (inchangée), icônes Lucide 0.462.0 (CDN, remplace Bootstrap Icons 1.11.3), pas de build step.

## Global Constraints

- Aucune modification de logique métier, d'API, de route Laravel, ou de structure de données (spec §Contraintes non négociables).
- Toutes les fonctionnalités et flux existants doivent rester identiques : mêmes appels `api.get/post/put/patch/delete`, mêmes id consommés par le JS inline de chaque page, même comportement des modals/toasts/tableaux.
- Tokens de couleur exacts (spec §Design tokens) : `--bg:#F8FAFC; --surface:#FFFFFF; --text:#0F172A; --text-muted:#64748B; --text-subtle:#94A3B8; --border:#E2E8F0; --border-soft:#F1F5F9; --accent:#2563EB; --accent-dark:#1D4ED8; --accent-soft:#EFF6FF; --success:#16A34A; --success-soft:#F0FDF4; --warning:#F59E0B; --warning-soft:#FFFBEB; --error:#DC2626; --error-soft:#FEF2F2; --radius:12px; --radius-lg:16px`.
- Icônes : Lucide `0.462.0` (épinglé), jamais Bootstrap Icons dans le nouveau code.
- Responsive identique sur les 8 pages : < 1140px grilles 4→2 colonnes ; < 900px sidebar → rail d'icônes 68px ; < 560px grilles → 1 colonne.
- Catégories reste un onglet dans `products.html` (pas de fichier séparé).
- Pas de page/route Paramètres système dans cette refonte.
- Nouvelle page Profil (`profile.html`) : consultation compte + changement de mot de passe via `PUT /auth/password` (endpoint existant, jamais utilisé par une UI) — aucune nouvelle route backend.
- Déploiement page par page : chaque tâche = une page livrée, vérifiée (aucune régression fonctionnelle), commitée, avant de passer à la suivante.
- Le script Lucide CDN est chargé avec `integrity="sha384-WBRt9V/J/erVtkEuP91HUFRv9MvHzFiFOp4/zTDp4xkcMG7aOeIv2asTV4yxFLWa" crossorigin="anonymous"` (hash SHA-384 vérifié contre `lucide@0.462.0/dist/umd/lucide.js` le 2026-07-13) sur toutes les pages, pour se protéger d'une compromission du CDN. Si la version Lucide change un jour, ce hash doit être recalculé (`curl` le fichier + `openssl dgst -sha384 -binary | openssl base64 -A`) — ne jamais changer la version sans recalculer le hash, sinon le script est bloqué silencieusement par le navigateur. La police Google Fonts (Inter) n'a volontairement pas de SRI : son CSS est servi dynamiquement selon le user-agent, donc son contenu (et son hash) varie légitimement d'un navigateur à l'autre.

## File Structure

- **Modify:** `frontend/css/app.css` — tokens + composants (réécriture complète, mêmes sélecteurs).
- **Modify:** `frontend/js/app.js` — ajout du helper `refreshIcons()` et de ses appels.
- **Modify:** `frontend/login.html`, `frontend/dashboard.html`, `frontend/products.html`, `frontend/stock.html`, `frontend/inventory-count.html`, `frontend/pos.html`, `frontend/reports.html`, `frontend/users.html` — icônes Lucide + composants restylés, structure DOM des sections concernées ajustée.
- **Create:** `frontend/profile.html` — nouvelle page.
- **No changes:** `frontend/js/api.js`, tout `backend/`, `frontend/manifest.json`, `frontend/sw.js`.

## Master Icon Mapping (Bootstrap Icons → Lucide)

Table de référence complète, vérifiée contre le registre officiel Lucide 0.462.0 (`icons/*.json` du repo `lucide-icons/lucide`). Chaque tâche ci-dessous liste uniquement le sous-ensemble utilisé sur sa page ; c'est toujours un lookup dans cette table, jamais une improvisation.

```
archive-fill              → archive
arrow-clockwise           → refresh-cw
arrow-down-short          → arrow-down
arrow-left                → arrow-left
arrow-up-short            → arrow-up
bag-fill                  → shopping-bag
bank2                     → landmark
bar-chart-fill            → chart-column
bar-chart-steps           → gauge
bell-fill                 → bell
box-arrow-in-down         → package-plus
box-arrow-in-right        → log-in
box-arrow-right           → log-out
box-arrow-up              → package-minus
box-seam                  → package
boxes                     → boxes
calendar-x-fill           → calendar-x
calendar3                 → calendar
cart-fill                 → shopping-cart
cart-x                    → shopping-cart
cart3                     → shopping-cart
cash                      → banknote
cash-coin                 → hand-coins
cash-register              → store
cash-stack                → wallet
check-circle-fill         → circle-check-big
check-lg                  → check
circle-fill               → circle
clipboard2-check          → clipboard-check
clipboard2-plus           → clipboard-plus
credit-card-2-front-fill  → credit-card
credit-card-fill          → credit-card
exclamation-circle-fill   → circle-alert
exclamation-diamond-fill  → triangle-alert
exclamation-triangle-fill → triangle-alert
eye                       → eye
eye-slash                 → eye-off
folder-fill               → folder
folder-plus               → folder-plus
folder2-open               → folder-open
graph-up-arrow             → trending-up
grid-1x2-fill              → layout-grid
hourglass-split             → hourglass
info-circle-fill            → info
key-fill                    → key
list                        → list
list-ul                     → list
lock                        → lock
lock-fill                   → lock
pencil                      → pencil
pencil-square                → square-pen
people-fill                  → users
percent                      → percent
person                       → user
person-badge-fill             → badge-check
person-plus-fill               → user-plus
phone-fill                     → smartphone
plus-lg                        → plus
receipt                        → receipt
safe2-fill                     → vault
search                         → search
shield-check                   → shield-check
tag-fill                       → tag
tags-fill                      → tags
toggle-off                     → toggle-left
toggle-on                      → toggle-right
trash3                         → trash-2
trophy-fill                    → trophy
unlock-fill                    → lock-open
upc-scan                       → scan-barcode
wallet2                        → wallet
x-circle-fill                  → circle-x
x-lg                           → x
```

Usage dans le markup : `<i class="bi bi-check-lg"></i>` devient `<i data-lucide="check" class="icon"></i>`. Dans le JS inline (template strings), `'<i class="bi bi-toggle-on"></i>'` devient `'<i data-lucide="toggle-right" class="icon"></i>'` — et **tout point d'injection dynamique doit être suivi d'un appel à `refreshIcons()`** (voir Task 2), sinon l'icône reste une balise `<i>` vide non convertie.

---

### Task 1: Design system CSS

**Files:**
- Modify: `frontend/css/app.css` (réécriture complète du fichier)

**Interfaces:**
- Produces: tous les sélecteurs CSS déjà consommés par les 8 pages (`.layout`, `.topbar`, `.sidebar`, `.nav-item`, `.nav-item.active`, `.nav-badge`, `.main`, `.page-title`, `.page-icon`, `.card`, `.card-header`, `.card-title`, `.card-body`, `.kpi-grid`, `.kpi-card` + modifiers `primary/accent/success/danger`, `.btn` + modifiers `primary/accent/danger/outline/ghost/sm/lg/block`, `.form-group`, `.form-label`, `.form-control`, `.form-select`, `.form-hint`, `.form-error`, `table/th/td`, `.badge` + modifiers `success/danger/warning/neutral/accent`, `.modal-backdrop`, `.modal`, `.modal-header/-title/-close/-body/-footer`, `.alert` + modifiers, `#toast-container`, `.toast` + modifiers, `.empty-state`, `.loading-spinner`, `.skeleton`, utilitaires `.grid/.grid-2/.grid-3/.flex/.items-center/.justify-between/.gap-*/.mb-*/.text-*/.font-*/.hidden`), plus une nouvelle classe `.icon` (dimensionnement des icônes Lucide) et `.nav-icon-wrap` (wrapper d'icône de nav).
- Consumes: rien (fichier autonome).

- [ ] **Step 1: Remplacer le contenu de `frontend/css/app.css`**

```css
/* ── Google Font Inter ── */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

/* ── Design Tokens ── */
:root {
  --bg:            #F8FAFC;
  --surface:       #FFFFFF;
  --text:          #0F172A;
  --text-muted:    #64748B;
  --text-subtle:   #94A3B8;
  --border:        #E2E8F0;
  --border-soft:   #F1F5F9;
  --accent:        #2563EB;
  --accent-dark:   #1D4ED8;
  --accent-soft:   #EFF6FF;
  --success:       #16A34A;
  --success-light: #F0FDF4;
  --warning:       #F59E0B;
  --warning-light: #FFFBEB;
  --danger:        #DC2626;
  --danger-light:  #FEF2F2;
  --shadow:        0 1px 2px rgba(15,23,42,.04);
  --shadow-md:     0 1px 3px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.04);
  --shadow-lg:     0 4px 12px rgba(15,23,42,.07);
  --shadow-xl:     0 10px 24px rgba(15,23,42,.10);
  --radius:        12px;
  --radius-sm:     8px;
  --radius-lg:     16px;
  --nav-h:         60px;
  --sidebar-w:     252px;
  --font:          'Inter', system-ui, -apple-system, sans-serif;
}

/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; -webkit-tap-highlight-color: transparent; scroll-behavior: smooth; }
body {
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  min-height: 100dvh;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
img { max-width: 100%; }
a  { color: inherit; text-decoration: none; }
button { font-family: inherit; cursor: pointer; border: none; }
input, select, textarea { font-family: inherit; }

/* ── Icons (Lucide) ── */
.icon { width: 16px; height: 16px; stroke-width: 1.75; vertical-align: -3px; flex-shrink: 0; }

/* ── Layout ── */
.layout {
  display: grid;
  grid-template-columns: var(--sidebar-w) 1fr;
  grid-template-rows: var(--nav-h) 1fr;
  min-height: 100dvh;
}

/* ── Topbar ── */
.topbar {
  grid-column: 1 / -1;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  padding: 0 20px;
  gap: 10px;
  position: sticky;
  top: 0;
  z-index: 100;
}
.topbar-brand {
  font-weight: 700;
  font-size: .95rem;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 9px;
  letter-spacing: -0.02em;
}
.topbar-brand .brand-icon {
  width: 30px;
  height: 30px;
  background: linear-gradient(150deg, var(--accent), #1E3A8A);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(37,99,235,.3);
}
.topbar-spacer { flex: 1; }
.topbar-user {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: .82rem;
  color: var(--text-muted);
}
.topbar-menu-btn {
  display: none;
  background: none;
  color: var(--text-muted);
  padding: 6px;
  border-radius: var(--radius-sm);
  align-items: center;
  justify-content: center;
  transition: background .12s;
}
.topbar-menu-btn:hover { background: var(--border-soft); color: var(--text); }

/* ── Sidebar ── */
.sidebar {
  background: var(--surface);
  border-right: 1px solid var(--border);
  padding: 18px 12px;
  display: flex;
  flex-direction: column;
  gap: 1px;
  overflow-y: auto;
  position: sticky;
  top: var(--nav-h);
  height: calc(100dvh - var(--nav-h));
}
.sidebar-section {
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--text-subtle);
  padding: 16px 12px 6px;
}
.nav-item {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  margin: 1px 0;
  border-radius: 8px;
  color: var(--text-muted);
  font-size: 13.5px;
  font-weight: 500;
  transition: background .15s, color .15s;
  cursor: pointer;
  text-decoration: none;
}
.nav-item:hover { background: var(--border-soft); color: var(--text); }
.nav-item.active { background: var(--accent-soft); color: var(--accent-dark); font-weight: 600; }
.nav-item.active::before {
  content: '';
  position: absolute;
  left: -12px; top: 50%; transform: translateY(-50%);
  width: 3px; height: 16px; border-radius: 0 3px 3px 0;
  background: var(--accent);
}
.nav-item .nav-icon-wrap {
  width: 22px; height: 22px; border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; transition: background .15s;
}
.nav-item.active .nav-icon-wrap { background: white; box-shadow: var(--shadow); }
.nav-item .icon { color: inherit; }
.nav-badge {
  margin-left: auto;
  background: var(--danger);
  color: #fff;
  font-size: 10.5px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 999px;
  min-width: 18px;
  text-align: center;
}

/* ── Main content ── */
.main {
  padding: 28px 32px;
  overflow-y: auto;
  height: calc(100dvh - var(--nav-h));
}
.page-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 22px;
  display: flex;
  align-items: center;
  gap: 10px;
  letter-spacing: -0.02em;
}
.page-title small {
  font-size: .78rem;
  font-weight: 400;
  color: var(--text-muted);
  letter-spacing: 0;
}
.page-icon {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  flex-shrink: 0;
}
.page-icon .icon { width: 17px; height: 17px; }

/* ── Cards ── */
.card {
  background: var(--surface);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow);
  border: 1px solid var(--border);
  overflow: hidden;
}
.card-header {
  padding: 15px 20px;
  border-bottom: 1px solid var(--border-soft);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.card-title {
  font-weight: 600;
  font-size: .85rem;
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--text);
}
.card-title .icon { color: var(--text-muted); }
.card-body { padding: 18px 20px; }

/* ── KPI Cards ── */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.kpi-card {
  background: var(--surface);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow);
  border: 1px solid var(--border);
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  transition: box-shadow .2s, transform .2s;
}
.kpi-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.kpi-card.primary { background: linear-gradient(155deg, var(--accent-soft), #FFFFFF 65%); border-color: #DBEAFE; }
.kpi-card.accent  { background: linear-gradient(155deg, var(--warning-light), #FFFFFF 65%); border-color: #FDE68A; }
.kpi-card.success { background: linear-gradient(155deg, var(--success-light), #FFFFFF 65%); border-color: #BBF7D0; }
.kpi-card.danger  { background: linear-gradient(155deg, var(--danger-light), #FFFFFF 65%); border-color: #FECACA; }
.kpi-label {
  font-size: 12px;
  font-weight: 500;
  color: var(--text-subtle);
}
.kpi-value {
  font-size: 1.6rem;
  font-weight: 700;
  line-height: 1.15;
  letter-spacing: -0.02em;
  color: var(--text);
  font-variant-numeric: tabular-nums;
}
.kpi-sub  { font-size: .76rem; color: var(--text-subtle); }
.kpi-icon { margin-bottom: 4px; }
.kpi-icon .icon { width: 18px; height: 18px; }

/* ── Buttons ── */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 9px 15px;
  border-radius: var(--radius-sm);
  font-size: .83rem;
  font-weight: 600;
  transition: all .15s;
  cursor: pointer;
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text);
  white-space: nowrap;
  letter-spacing: -0.01em;
}
.btn:hover:not(:disabled) { border-color: #CBD5E1; background: var(--border-soft); }
.btn:disabled { opacity: .45; cursor: not-allowed; }
.btn-primary {
  background: var(--accent);
  border-color: var(--accent);
  color: #fff;
  box-shadow: 0 1px 2px rgba(37,99,235,.25);
}
.btn-primary:hover:not(:disabled) { background: var(--accent-dark); border-color: var(--accent-dark); box-shadow: 0 4px 10px rgba(37,99,235,.28); }
.btn-accent {
  background: var(--warning);
  border-color: var(--warning);
  color: #fff;
}
.btn-accent:hover:not(:disabled) { background: #D97706; border-color: #D97706; }
.btn-danger {
  background: var(--danger);
  border-color: var(--danger);
  color: #fff;
}
.btn-danger:hover:not(:disabled) { background: #B91C1C; border-color: #B91C1C; }
.btn-outline {
  background: transparent;
  border: 1px solid var(--border);
  color: var(--text-muted);
}
.btn-outline:hover:not(:disabled) { border-color: var(--text-subtle); color: var(--text); background: var(--border-soft); }
.btn-ghost { background: transparent; border-color: transparent; color: var(--text-muted); }
.btn-ghost:hover:not(:disabled) { background: var(--border-soft); color: var(--text); }
.btn-sm    { padding: 5px 10px; font-size: .76rem; border-radius: 7px; }
.btn-lg    { padding: 12px 22px; font-size: .92rem; border-radius: var(--radius); }
.btn-block { width: 100%; }
.btn-icon  { padding: 7px; border-radius: var(--radius-sm); }

/* ── Forms ── */
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-label { font-size: 12.5px; font-weight: 600; color: var(--text); }
.form-control {
  padding: 9px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: .85rem;
  background: var(--surface);
  color: var(--text);
  transition: border-color .15s, box-shadow .15s;
  width: 100%;
}
.form-control:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-soft);
}
.form-control::placeholder { color: var(--text-subtle); }
.form-select {
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 11px center;
  background-size: 11px;
  padding-right: 32px;
}
.form-hint  { font-size: .74rem; color: var(--text-subtle); }
.form-error { font-size: .74rem; color: var(--danger); font-weight: 500; }

/* ── Tables ── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: .85rem; }
th {
  text-align: left;
  padding: 0 20px 11px;
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: var(--text-subtle);
  white-space: nowrap;
}
th.text-right { text-align: right; }
td { padding: 13px 20px; border-top: 1px solid var(--border-soft); vertical-align: middle; }
tr:hover td { background: var(--border-soft); }

/* ── Badges ── */
.badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
}
.badge .icon { width: 11px; height: 11px; }
.badge-success { background: var(--success-light); color: #15803D; }
.badge-danger  { background: var(--danger-light);  color: #991B1B; }
.badge-warning { background: var(--warning-light); color: #92400E; }
.badge-neutral { background: var(--border-soft);   color: var(--text-muted); }
.badge-accent  { background: var(--warning-light); color: #92400E; }

/* ── Modals ── */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15,23,42,.35);
  backdrop-filter: blur(3px);
  -webkit-backdrop-filter: blur(3px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 16px;
  opacity: 0;
  pointer-events: none;
  transition: opacity .18s;
}
.modal-backdrop.show { opacity: 1; pointer-events: all; }
.modal {
  background: var(--surface);
  border-radius: var(--radius-lg);
  width: 100%;
  max-width: 520px;
  max-height: 90dvh;
  overflow-y: auto;
  box-shadow: var(--shadow-xl);
  border: 1px solid var(--border);
  transform: scale(.97) translateY(6px);
  transition: transform .18s cubic-bezier(.34,1.2,.64,1);
}
.modal-backdrop.show .modal { transform: scale(1) translateY(0); }
.modal-header {
  padding: 18px 22px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--border-soft);
}
.modal-title { font-weight: 700; font-size: .92rem; display: flex; align-items: center; gap: 8px; }
.modal-close {
  background: var(--border-soft);
  color: var(--text-muted);
  border-radius: 7px;
  transition: all .15s;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
}
.modal-close:hover { background: var(--danger-light); color: var(--danger); }
.modal-body   { padding: 20px 22px; display: flex; flex-direction: column; gap: 14px; }
.modal-footer { padding: 12px 22px; border-top: 1px solid var(--border-soft); display: flex; gap: 8px; justify-content: flex-end; }

/* ── Alerts ── */
.alert {
  padding: 10px 13px;
  border-radius: var(--radius-sm);
  font-size: .85rem;
  display: flex;
  align-items: flex-start;
  gap: 9px;
  font-weight: 500;
}
.alert-danger  { background: var(--danger-light);  color: #991B1B; }
.alert-success { background: var(--success-light); color: #15803D; }
.alert-warning { background: var(--warning-light); color: #92400E; }

/* ── Toasts ── */
#toast-container {
  position: fixed;
  top: 72px;
  right: 14px;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  gap: 7px;
}
.toast {
  background: var(--surface);
  color: var(--text);
  border: 1px solid var(--border);
  padding: 11px 16px;
  border-radius: var(--radius);
  font-size: .85rem;
  font-weight: 500;
  box-shadow: var(--shadow-xl);
  display: flex;
  align-items: center;
  gap: 9px;
  min-width: 260px;
  max-width: 360px;
  animation: slideInToast .2s cubic-bezier(.34,1.4,.64,1);
}
.toast.success { border-left: 3px solid var(--success); }
.toast.success .icon { color: var(--success); }
.toast.danger  { border-left: 3px solid var(--danger); }
.toast.danger  .icon { color: var(--danger); }
.toast.warning { border-left: 3px solid var(--warning); }
.toast.warning .icon { color: var(--warning); }
@keyframes slideInToast {
  from { opacity: 0; transform: translateX(16px); }
  to   { opacity: 1; transform: translateX(0); }
}

/* ── Empty state ── */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 44px 24px;
  color: var(--text-subtle);
  text-align: center;
}
.empty-state .icon-wrap {
  width: 44px; height: 44px; border-radius: 50%;
  background: var(--border-soft);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 4px;
}
.empty-state .icon-wrap .icon { width: 20px; height: 20px; color: var(--text-subtle); }
.empty-state p { font-size: .85rem; }

/* ── Loading ── */
.loading-spinner {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid rgba(15,23,42,.1);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin .6s linear infinite;
  flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }
.skeleton {
  background: linear-gradient(90deg, var(--border-soft) 25%, #E9EDF3 50%, var(--border-soft) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.4s ease infinite;
  border-radius: var(--radius-sm);
}
@keyframes shimmer {
  from { background-position: 200% 0; }
  to   { background-position: -200% 0; }
}

/* ── Utilities ── */
.grid { display: grid; gap: 16px; }
.grid-2 { grid-template-columns: repeat(2, 1fr); }
.grid-3 { grid-template-columns: repeat(3, 1fr); }
.flex { display: flex; }
.flex-col { flex-direction: column; }
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.mb-2 { margin-bottom: 8px; }
.mb-4 { margin-bottom: 16px; }
.mb-6 { margin-bottom: 24px; }
.mt-4 { margin-top: 16px; }
.ml-1 { margin-left: 4px; }
.text-muted   { color: var(--text-muted); }
.text-danger  { color: var(--danger); }
.text-success { color: var(--success); }
.text-accent  { color: var(--warning); }
.text-primary { color: var(--accent); }
.text-right  { text-align: right; }
.text-center { text-align: center; }
.font-bold   { font-weight: 700; }
.font-sm     { font-size: .8rem; }
.w-full      { width: 100%; }
.hidden      { display: none !important; }

/* ── Sidebar overlay ── */
.sidebar-overlay { display: none; }

/* ── Responsive ── */
@media (max-width: 1140px) {
  .kpi-grid { grid-template-columns: repeat(2, 1fr); }
  .grid-3 { grid-template-columns: 1fr; }
}
@media (max-width: 900px) {
  .layout { grid-template-columns: 1fr; }
  .topbar-menu-btn { display: flex; }
  .sidebar {
    position: fixed;
    top: var(--nav-h);
    left: 0;
    height: calc(100dvh - var(--nav-h));
    z-index: 200;
    width: var(--sidebar-w);
    transform: translateX(-100%);
    transition: transform .22s cubic-bezier(.4,0,.2,1);
  }
  .sidebar.open { transform: translateX(0); box-shadow: 4px 0 24px rgba(15,23,42,.12); }
  .sidebar-overlay {
    position: fixed;
    inset: 0;
    top: var(--nav-h);
    background: rgba(15,23,42,.25);
    z-index: 199;
    display: none;
  }
  .sidebar-overlay.show { display: block; }
  .main { padding: 16px; height: calc(100dvh - var(--nav-h)); }
  .grid-2 { grid-template-columns: 1fr; }
  .modal { max-width: 100%; max-height: 95dvh; border-radius: var(--radius); }
}
@media (max-width: 560px) {
  .kpi-grid { grid-template-columns: 1fr; }
  .kpi-value { font-size: 1.3rem; }
  .main { padding: 12px; }
}
```

- [ ] **Step 2: Vérifier visuellement**

Ouvrir `frontend/dashboard.html` dans un navigateur (serveurs `php artisan serve --port=8000` et `php -S localhost:3000` depuis `frontend/`) après connexion. Le layout doit s'afficher avec fond gris très clair (`#F8FAFC`), cartes blanches à bordure fine, mais **les icônes seront cassées** (Bootstrap Icons visuel générique) tant que Task 2+ n'est pas fait — normal à ce stade, on vérifie uniquement que rien n'est cassé structurellement (pas de superposition, pas d'élément qui déborde).

- [ ] **Step 3: Commit**

```bash
git add frontend/css/app.css
git commit -m "design: nouveau design system (tokens + composants) sans casser le markup existant"
```

---

### Task 2: Lucide dans la couche JS partagée

**Files:**
- Modify: `frontend/js/app.js`

**Interfaces:**
- Produces: `refreshIcons()` (fonction globale, appelable depuis n'importe quelle page) — convertit tous les `<i data-lucide="...">` présents dans le DOM en SVG inline. Appelée automatiquement en fin de `initLayout()`, `renderTable()`, et `toast()`.
- Consumes: la variable globale `window.lucide` fournie par le script CDN Lucide (chargé dans le `<head>` de chaque page dans les tâches suivantes).

- [ ] **Step 1: Ajouter le helper et ses appels dans `frontend/js/app.js`**

Ajouter cette fonction juste après le bloc `escHtml` (haut de fichier) :

```javascript
// ── Lucide icons ──────────────────────────────────────────────────────────────
function refreshIcons() {
  if (window.lucide) window.lucide.createIcons();
}
```

Modifier la fin de `initLayout()` (juste avant la fermeture de la fonction, après l'appel à `refreshAlertBadge()`) :

```javascript
  // Load alert count in sidebar badge
  refreshAlertBadge();

  refreshIcons();
}
```

Modifier `toast()` pour convertir l'icône injectée : remplacer la ligne finale `container.appendChild(t);` par :

```javascript
  container.appendChild(t);
  refreshIcons();
```

Et remplacer les icônes Bootstrap codées en dur dans `toast()` :

```javascript
function toast(msg, type = 'success', duration = 3500) {
  const icons = {
    success: '<i data-lucide="circle-check-big" class="icon"></i>',
    danger:  '<i data-lucide="circle-x" class="icon"></i>',
    warning: '<i data-lucide="triangle-alert" class="icon"></i>',
  };
  const container = document.getElementById('toast-container');
  if (!container) return;

  const t = document.createElement('div');
  t.className = `toast ${type}`;
  const iconSpan = document.createElement('span');
  iconSpan.innerHTML = icons[type] || '<i data-lucide="info" class="icon"></i>';
  const msgSpan = document.createElement('span');
  msgSpan.textContent = msg;
  t.appendChild(iconSpan);
  t.appendChild(msgSpan);
  container.appendChild(t);
  refreshIcons();

  setTimeout(() => {
    t.style.opacity = '0';
    t.style.transform = 'translateX(20px)';
    t.style.transition = 'all .3s';
    setTimeout(() => t.remove(), 300);
  }, duration);
}
```

Modifier `renderTable()` pour convertir les icônes injectées via les lignes de tableau — ajouter `refreshIcons();` juste avant le `return;` final et avant le `tbody.innerHTML = rows.join('');` :

```javascript
function renderTable(tbodyId, rows, emptyMsg = 'Aucune donnée') {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!rows || rows.length === 0) {
    const cols = tbody.closest('table')?.querySelectorAll('th').length || 4;
    tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted" style="padding:32px">${emptyMsg}</td></tr>`;
    refreshIcons();
    return;
  }
  tbody.innerHTML = rows.join('');
  refreshIcons();
}
```

- [ ] **Step 2: Vérifier**

`frontend/js/app.js` ne doit contenir aucune occurrence restante de `bi bi-` ou `class="bi `. Vérifier avec :

```bash
grep -n "bi bi-\|class=\"bi " frontend/js/app.js
```

Résultat attendu : aucune ligne (grep retourne un code de sortie non-zéro, pas d'output).

- [ ] **Step 3: Commit**

```bash
git add frontend/js/app.js
git commit -m "design: ajoute refreshIcons() pour la conversion Lucide dynamique dans app.js"
```

---

### Task 3: Bloc topbar+sidebar canonique (référence pour Tasks 5–12)

Ce bloc est **identique** dans `dashboard.html`, `products.html`, `stock.html`, `inventory-count.html`, `pos.html`, `reports.html`, `users.html`, `profile.html`. Chaque page y insère juste son propre `<main>` après. `login.html` n'a pas de sidebar (page autonome, traitée à part en Task 4).

Les `href` couvrent la navigation réelle (pas de changement JS requis : `initLayout()` détecte déjà la page active via `location.pathname` et masque les items par `data-role`).

**Bloc canonique complet** (remplace le `<header class="topbar">…</nav>` existant tel quel dans chaque page de Tasks 5–12) :

```html
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
      <span class="nav-icon-wrap"><i data-lucide="layout-grid" class="icon"></i></span> Dashboard
    </a>
    <a href="pos.html" class="nav-item">
      <span class="nav-icon-wrap"><i data-lucide="store" class="icon"></i></span> Caisse (POS)
    </a>
    <div class="sidebar-section" data-role="proprietaire,gestionnaire">Stock</div>
    <a href="stock.html" class="nav-item" data-role="proprietaire,gestionnaire">
      <span class="nav-icon-wrap"><i data-lucide="boxes" class="icon"></i></span> Stock
      <span class="nav-badge hidden" id="alert-badge">0</span>
    </a>
    <a href="products.html" class="nav-item" data-role="proprietaire,gestionnaire">
      <span class="nav-icon-wrap"><i data-lucide="tag" class="icon"></i></span> Produits
    </a>
    <div class="sidebar-section" data-role="proprietaire">Gestion</div>
    <a href="users.html" class="nav-item" data-role="proprietaire">
      <span class="nav-icon-wrap"><i data-lucide="users" class="icon"></i></span> Utilisateurs
    </a>
    <a href="reports.html" class="nav-item" data-role="proprietaire">
      <span class="nav-icon-wrap"><i data-lucide="chart-column" class="icon"></i></span> Rapports
    </a>
    <div class="sidebar-section">Compte</div>
    <a href="profile.html" class="nav-item">
      <span class="nav-icon-wrap"><i data-lucide="user" class="icon"></i></span> Profil
    </a>
  </nav>
```

Note : `reports.html`, `users.html`, `pos.html` actuels n'ont pas de `data-role` sur certains `<a>` (ex. `reports.html` et `users.html` n'en avaient aucun) — le bloc canonique les uniformise en réappliquant les mêmes règles de rôle que `dashboard.html`/`products.html`/`stock.html` (cohérence demandée par le client). C'est un changement de comportement mineur et voulu : un `caissier` ou `vendeur` ne verra plus ces liens dans la sidebar (ils étaient déjà inaccessibles côté API pour ces rôles — `role:proprietaire` — donc ça corrige une incohérence UI existante, pas une régression).

**Fermeture du layout** : chaque page garde son `</div>` de fermeture de `.layout` existant après son `<main>` (inchangé).

- [ ] **Vérification de ce bloc** : se fait implicitement à chaque page (Tasks 5–12), pas de commit séparé pour cette tâche — elle documente le bloc réutilisé.

---

### Task 4: Page Login

**Files:**
- Modify: `frontend/login.html`

**Interfaces:**
- Consumes: `api.post('/auth/login', {username, password})` (inchangé).
- Produces: rien de neuf — page terminale.

- [ ] **Step 1: `<head>` — remplacer Bootstrap Icons par Lucide**

Remplacer :
```html
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
```
par :
```html
  <script src="https://unpkg.com/lucide@0.462.0/dist/umd/lucide.js" integrity="sha384-WBRt9V/J/erVtkEuP91HUFRv9MvHzFiFOp4/zTDp4xkcMG7aOeIv2asTV4yxFLWa" crossorigin="anonymous"></script>
```

- [ ] **Step 2: Icônes du panneau de marque (gauche) et remplacer `.bi-icon` par `.icon`**

Dans le CSS inline de la page, remplacer les 2 occurrences de `.bi-icon` par `.icon` (lignes ~121 et ~126) :
```css
    .input-wrap .icon {
      position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
      color: #9ca3af; font-size: .9rem; pointer-events: none;
    }
    .input-wrap .form-control { padding-left: 36px; }
    .input-wrap:focus-within .icon { color: var(--accent); }
```
(remplacer aussi `#4f46e5` → `var(--accent)` dans ce bloc et dans `.bp-logo-icon`, `.bp-headline h2 em`, `.mobile-brand .mb-icon`, `.pwd-toggle:hover` — cohérence avec le token accent).

Remplacer les icônes dans le markup (table de correspondance) :

| Ancien | Nouveau |
|---|---|
| `<i class="bi bi-bag-fill"></i>` (×2, logo) | `<i data-lucide="shopping-bag" class="icon"></i>` |
| `<i class="bi bi-cash-register"></i>` | `<i data-lucide="store" class="icon"></i>` |
| `<i class="bi bi-boxes"></i>` | `<i data-lucide="boxes" class="icon"></i>` |
| `<i class="bi bi-bar-chart-fill"></i>` | `<i data-lucide="chart-column" class="icon"></i>` |
| `<i class="bi bi-person bi-icon"></i>` | `<i data-lucide="user" class="icon"></i>` |
| `<i class="bi bi-lock bi-icon"></i>` | `<i data-lucide="lock" class="icon"></i>` |
| `<i class="bi bi-eye" id="toggle-icon"></i>` | `<i data-lucide="eye" id="toggle-icon" class="icon"></i>` |
| `<i class="bi bi-box-arrow-in-right"></i>` (bouton submit) | `<i data-lucide="log-in" class="icon"></i>` |

- [ ] **Step 3: Adapter le JS inline**

Remplacer le toggle de mot de passe (actuellement change `icon.className`) :

```javascript
    document.getElementById('toggle-pwd').addEventListener('click', () => {
      const pwd  = document.getElementById('password');
      const icon = document.getElementById('toggle-icon');
      const show = pwd.type === 'password';
      pwd.type = show ? 'text' : 'password';
      icon.setAttribute('data-lucide', show ? 'eye-off' : 'eye');
      refreshIcons();
    });
```

Remplacer la construction de l'icône d'erreur et le fallback du bouton submit :

```javascript
      try {
        const data = await api.post('/auth/login', { username, password });
        localStorage.setItem('token', data.token);
        localStorage.setItem('user', JSON.stringify({
          id:   data.user.id,
          name: data.user.name,
          role: data.user.role,
        }));
        window.location.href = data.user.role === 'caissier' ? 'pos.html' : 'dashboard.html';
      } catch (err) {
        errEl.innerHTML = '';
        const icon = document.createElement('i');
        icon.setAttribute('data-lucide', 'circle-alert');
        icon.className = 'icon';
        const msg = document.createElement('span');
        msg.textContent = err.message;
        errEl.appendChild(icon);
        errEl.appendChild(msg);
        errEl.style.display = 'flex';
        btnEl.disabled = false;
        btnEl.innerHTML = '<i data-lucide="log-in" class="icon"></i> Se connecter';
        refreshIcons();
      }
```

Ajouter `<script src="js/app.js"></script>` juste après `<script src="js/api.js"></script>` (la page n'incluait que `api.js` — elle a besoin de `refreshIcons()`, `escHtml` n'est pas utilisé ici mais `app.js` ne fait rien de bloquant sans layout : `initLayout()` fait des `document.getElementById` avec `?.` optionnel, donc sans risque sur une page sans sidebar/topbar). Ajouter `refreshIcons();` juste après l'enregistrement du service worker en tout début de script :

```javascript
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js');
    if (localStorage.getItem('token')) window.location.href = 'dashboard.html';
    refreshIcons();
```

- [ ] **Step 4: Vérification manuelle**

1. `php artisan serve --port=8000` (backend) + `php -S localhost:3000` (frontend, depuis `frontend/`).
2. Ouvrir `http://localhost:3000/login.html`.
3. Vérifier : logo, 3 features (Caisse/Inventaire/Rapports), champs identifiant/mot de passe avec icônes Lucide visibles (pas de carré vide/glyphe cassé).
4. Cliquer l'œil → le mot de passe devient visible, l'icône change vers "eye-off".
5. Se connecter avec `admin` / `admin123` → redirection vers `dashboard.html`.
6. Se connecter avec un mauvais mot de passe → message d'erreur rouge avec icône, formulaire réactivé.

- [ ] **Step 5: Commit**

```bash
git add frontend/login.html
git commit -m "design: applique le nouveau design system a la page login"
```

---

### Task 5: Page Dashboard

**Files:**
- Modify: `frontend/dashboard.html`

**Interfaces:**
- Consumes: `api.get('/dashboard')` (inchangé), `fmt()`, `escHtml()`, `renderTable()`, `toast()`, `requireAuth()` (inchangés, définis dans `app.js`).

- [ ] **Step 1: `<head>`**

Remplacer le `<link>` Bootstrap Icons par le script Lucide (identique à Task 4 Step 1).

- [ ] **Step 2: Remplacer `<header class="topbar">` … `</nav>` par le bloc canonique de Task 3**, en gardant le `<main>` existant après.

- [ ] **Step 3: Icônes statiques du `<main>` (hors JS)**

| Ancien | Nouveau |
|---|---|
| `<i class="bi bi-grid-1x2-fill"></i>` (page-icon) | `<i data-lucide="layout-grid" class="icon"></i>` |
| `<i class="bi bi-bell-fill" style="color:#f59e0b"></i>` | `<i data-lucide="bell" class="icon" style="color:var(--warning)"></i>` |
| `<i class="bi bi-person-badge-fill" style="color:#4f46e5"></i>` | `<i data-lucide="badge-check" class="icon" style="color:var(--accent)"></i>` |
| `<i class="bi bi-trophy-fill" style="color:#f59e0b"></i>` | `<i data-lucide="trophy" class="icon" style="color:var(--warning)"></i>` |
| `<i class="bi bi-calendar3" style="color:#4f46e5"></i>` | `<i data-lucide="calendar" class="icon" style="color:var(--accent)"></i>` |

Le `style="background:#4f46e5"` du `.page-icon` devient `style="background:var(--accent)"`.

- [ ] **Step 4: Icônes générées dynamiquement dans `loadDashboard()`**

Remplacer chaque fragment d'icône dans le template JS :

```javascript
    const evol = d.ca.evolution_pct !== null
      ? `${d.ca.evolution_pct > 0 ? '<i data-lucide="trending-up" class="icon"></i>' : '<i data-lucide="trending-down" class="icon"></i>'} ${Math.abs(d.ca.evolution_pct)}% vs hier`
      : 'Pas de données hier';

    document.getElementById('kpi-grid').innerHTML = `
      <div class="kpi-card primary">
        <div class="kpi-icon"><i data-lucide="wallet" class="icon" style="color:var(--accent)"></i></div>
        <div class="kpi-label">CA du jour</div>
        <div class="kpi-value">${fmt(d.ca.jour)}</div>
        <div class="kpi-sub">${evol}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i data-lucide="shopping-bag" class="icon" style="color:var(--warning)"></i></div>
        <div class="kpi-label">Ventes</div>
        <div class="kpi-value">${d.ventes.nb_jour}</div>
        <div class="kpi-sub">Panier moy. ${fmt(d.ventes.panier_moyen)}</div>
      </div>
      <div class="kpi-card success">
        <div class="kpi-icon"><i data-lucide="trending-up" class="icon" style="color:var(--success)"></i></div>
        <div class="kpi-label">Bénéfice estimé</div>
        <div class="kpi-value">${fmt(d.benefice.jour)}</div>
        <div class="kpi-sub">Marge ${escHtml(String(d.benefice.marge_pct))}%</div>
      </div>
      <div class="kpi-card ${d.alertes.total > 0 ? 'danger' : ''}">
        <div class="kpi-icon"><i data-lucide="${d.alertes.total > 0 ? 'triangle-alert' : 'circle-check-big'}" class="icon" style="color:${d.alertes.total > 0 ? 'var(--danger)' : 'var(--success)'}"></i></div>
        <div class="kpi-label">Alertes</div>
        <div class="kpi-value">${d.alertes.total}</div>
        <div class="kpi-sub">${d.alertes.rupture} rupture · ${d.alertes.stock_bas} stock bas</div>
      </div>
    `;

    // Alertes détail
    const alertLines = [
      d.alertes.rupture    > 0 ? `<div class="alert alert-danger mb-2"><i data-lucide="circle-x" class="icon"></i> ${d.alertes.rupture} produit(s) en rupture de stock</div>`        : '',
      d.alertes.stock_bas  > 0 ? `<div class="alert alert-warning mb-2"><i data-lucide="triangle-alert" class="icon"></i> ${d.alertes.stock_bas} produit(s) en stock bas</div>`  : '',
      d.alertes.peremption > 0 ? `<div class="alert alert-warning mb-2"><i data-lucide="calendar-x" class="icon"></i> ${d.alertes.peremption} produit(s) bientôt périmé(s)</div>`       : '',
      d.alertes.ecarts_caisse > 0 ? `<div class="alert alert-danger mb-2"><i data-lucide="wallet" class="icon"></i> ${d.alertes.ecarts_caisse} session(s) avec écart de caisse</div>`     : '',
    ].filter(Boolean);
    document.getElementById('alertes-body').innerHTML = alertLines.length
      ? alertLines.join('')
      : '<div class="empty-state" style="padding:24px"><div class="icon-wrap"><i data-lucide="circle-check-big" class="icon" style="color:var(--success)"></i></div><p>Aucune alerte</p></div>';
```

Et pour le rendu des caissiers, garder `<i class="bi bi-circle-fill">` → remplacer par un point CSS pur (pas une icône Lucide, plus fidèle au design "pill" avec `pill-dot`) :

```javascript
    const caissierLines = d.caissiers.map(c => `
      <div class="flex items-center justify-between mb-2">
        <span class="font-bold">${escHtml(c.name)}</span>
        <div class="flex gap-2">
          <span class="badge ${c.en_ligne ? 'badge-success' : 'badge-neutral'}">
            <span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block"></span>
            ${c.en_ligne ? 'En ligne' : 'Hors ligne'}
          </span>
          <span class="badge ${c.caisse_ouverte ? 'badge-accent' : 'badge-neutral'}">
            <i data-lucide="store" class="icon"></i>
            ${c.caisse_ouverte ? 'Ouverte' : 'Fermée'}
          </span>
        </div>
      </div>`);
```

Après l'assignation de `caissiers-body` et de `periods-body` (qui n'a pas d'icônes), ajouter un appel `refreshIcons();` en toute fin de `loadDashboard()` (avant l'accolade fermante du `try`, après l'assignation de `periods-body`) — nécessaire car ce fichier construit son HTML par assignations directes (`innerHTML =`), pas via `renderTable()`.

```javascript
    document.getElementById('periods-body').innerHTML = `
      ...
    `;
    refreshIcons();

  } catch (err) {
    toast(err.message, 'danger');
  }
}
```

- [ ] **Step 5: Vérification manuelle**

1. Se connecter en `admin`/`admin123`, arriver sur `dashboard.html`.
2. Vérifier que les 4 KPI affichent des icônes Lucide correctes (portefeuille, sac, tendance, alerte).
3. Vérifier la section Alertes (si aucune alerte : coche verte + "Aucune alerte" bien centré).
4. Vérifier la section Caissiers actifs : badges "En ligne/Hors ligne" avec un point coloré, "Ouverte/Fermée" avec icône magasin.
5. Vérifier Top 5 produits et Périodes s'affichent sans erreur console.
6. Recharger la page 2-3 fois (le `setInterval` de 60s ne doit pas planter, vérifier la console navigateur : aucune erreur `lucide is not defined` ni `Cannot read property of null`).

- [ ] **Step 6: Commit**

```bash
git add frontend/dashboard.html
git commit -m "design: applique le nouveau design system a la page dashboard"
```

---

### Task 6: Page Produits (+ onglet Catégories)

**Files:**
- Modify: `frontend/products.html`

**Interfaces:**
- Consumes: `api.get/post/put/patch/delete` sur `/categories` et `/products` (inchangé).

- [ ] **Step 1: `<head>`** — remplacer Bootstrap Icons par Lucide (identique Task 4 Step 1).

- [ ] **Step 2: Remplacer le bloc topbar+sidebar** par le bloc canonique de Task 3.

- [ ] **Step 3: Icônes statiques**

| Ancien | Nouveau |
|---|---|
| `<i class="bi bi-tag-fill"></i>` (page-icon) | `<i data-lucide="tag" class="icon"></i>` |
| `style="background:#7c3aed"` (page-icon) | `style="background:var(--accent)"` |
| `<i class="bi bi-folder-fill" style="color:#f59e0b"></i>` (titre carte Catégories) | `<i data-lucide="folder" class="icon" style="color:var(--warning)"></i>` |
| `<i class="bi bi-plus-lg"></i>` (×2, boutons "Catégorie"/"Produit") | `<i data-lucide="plus" class="icon"></i>` |
| `<i class="bi bi-tag-fill" style="color:#4f46e5"></i>` (titre carte Produits) | `<i data-lucide="tag" class="icon" style="color:var(--accent)"></i>` |
| `<i class="bi bi-search"></i>` (barre recherche) | `<i data-lucide="search" class="icon"></i>` |
| `<i class="bi bi-folder-plus" ...></i>` (modal nouvelle catégorie) | `<i data-lucide="folder-plus" class="icon" style="color:var(--warning)"></i>` |
| `<i class="bi bi-x-lg"></i>` (×2, fermeture modals) | `<i data-lucide="x" class="icon"></i>` |
| `<i class="bi bi-check-lg"></i>` (×2, boutons Enregistrer) | `<i data-lucide="check" class="icon"></i>` |

- [ ] **Step 4: Icônes générées dans le JS inline**

```javascript
    renderTable('cats-tbody', categories.map(c => `
      <tr>
        <td><strong>${escHtml(c.name)}</strong></td>
        <td>${escHtml(c.description ?? '—')}</td>
        <td><span class="badge badge-neutral">${c.products_count ?? 0} produit(s)</span></td>
        <td>${isOwner ? `
          <button class="btn btn-sm btn-outline" onclick="editCat(${c.id})">
            <i data-lucide="pencil" class="icon"></i> Modifier
          </button>
          <button class="btn btn-sm btn-danger" style="margin-left:6px" onclick="deleteCat(${c.id})">
            <i data-lucide="trash-2" class="icon"></i>
          </button>` : ''}</td>
      </tr>`), 'Aucune catégorie');
```

```javascript
        <td>
          <strong>${escHtml(p.name)}</strong>
          ${p.barcode ? `<br><span class="text-muted font-sm"><i data-lucide="scan-barcode" class="icon"></i> ${escHtml(p.barcode)}</span>` : ''}
        </td>
```

```javascript
        <td>${isOwner ? `
          <button class="btn btn-sm btn-outline" onclick="editProduct(${p.id})">
            <i data-lucide="pencil" class="icon"></i>
          </button>
          <button class="btn btn-sm btn-ghost" style="margin-left:4px" onclick="toggleProduct(${p.id})">
            ${p.is_active ? '<i data-lucide="toggle-right" class="icon"></i>' : '<i data-lucide="toggle-left" class="icon"></i>'}
          </button>` : ''}</td>
```

Dans `editCat()` et `editProduct()`, remplacer les titres de modal :

```javascript
  document.getElementById('cat-modal-title').innerHTML = '<i data-lucide="folder-open" class="icon" style="color:var(--warning)"></i> Modifier catégorie';
  openModal('modal-cat');
  refreshIcons();
```

```javascript
  document.getElementById('prod-modal-title').innerHTML = '<i data-lucide="square-pen" class="icon" style="color:var(--accent)"></i> Modifier produit';
  openModal('modal-product');
  refreshIcons();
```

Et dans `bindEvents()`, les deux gestionnaires "Nouvelle catégorie"/"Nouveau produit" :

```javascript
    document.getElementById('cat-modal-title').innerHTML = '<i data-lucide="folder-plus" class="icon" style="color:var(--warning)"></i> Nouvelle catégorie';
    openModal('modal-cat');
    refreshIcons();
```

```javascript
    document.getElementById('prod-modal-title').innerHTML = '<i data-lucide="tag" class="icon" style="color:var(--accent)"></i> Nouveau produit';
    openModal('modal-product');
    refreshIcons();
```

(`renderTable` appelle déjà `refreshIcons()` en interne depuis Task 2 — pas besoin de l'ajouter après `loadCategories()`/`loadProducts()`.)

- [ ] **Step 5: Vérification manuelle**

1. Aller sur `products.html` connecté en `admin`.
2. Onglet Catégories (carte du haut) : icônes dossier visibles, bouton "+ Catégorie" fonctionnel (ouvre modal), Modifier/Supprimer fonctionnels.
3. Section Produits : recherche par nom fonctionne (debounce 300ms), filtres catégorie/statut fonctionnent, badges Actif/Inactif corrects.
4. Créer un produit test, vérifier qu'il apparaît dans la liste avec les bonnes icônes (toggle actif/inactif).
5. Vérifier qu'aucune icône ne reste vide (balise `<i>` sans SVG converti) après une action (créer/modifier/toggler).

- [ ] **Step 6: Commit**

```bash
git add frontend/products.html
git commit -m "design: applique le nouveau design system a la page produits/categories"
```

---

### Task 7: Page Stock

**Files:**
- Modify: `frontend/stock.html`

**Interfaces:**
- Consumes: `api.get/post` sur `/stock/dashboard`, `/stock/entries`, `/stock/exits`, `/inventories`, `/products`, `/categories` (inchangé).

- [ ] **Step 1: `<head>`** — remplacer Bootstrap Icons par Lucide.

- [ ] **Step 2: Adapter les styles locaux `.tab-btn.active`**

Remplacer le dégradé indigo/violet codé en dur par le token accent :
```css
    .tab-btn.active {
      background: var(--accent);
      color: white;
      box-shadow: 0 4px 10px rgba(37,99,235,.3);
    }
```
Idem pour `.dot-normal/.dot-bas/.dot-rupture` — garder `#10b981/#f59e0b/#ef4444` → remplacer par `var(--success)/var(--warning)/var(--danger)`.

- [ ] **Step 3: Remplacer le bloc topbar+sidebar** par le bloc canonique de Task 3.

- [ ] **Step 4: Icônes statiques** (page-icon, onglets, en-têtes de carte, boutons, modals)

| Ancien | Nouveau |
|---|---|
| `<i class="bi bi-boxes"></i>` (page-icon) | `<i data-lucide="boxes" class="icon"></i>` |
| `style="background:#059669"` (page-icon) | `style="background:var(--success)"` |
| `<i class="bi bi-bar-chart-steps"></i>` (onglet Vue Stock) | `<i data-lucide="gauge" class="icon"></i>` |
| `<i class="bi bi-box-arrow-in-down"></i>` (onglet Entrées) | `<i data-lucide="package-plus" class="icon"></i>` |
| `<i class="bi bi-box-arrow-up"></i>` (onglet Sorties) | `<i data-lucide="package-minus" class="icon"></i>` |
| `<i class="bi bi-clipboard2-check"></i>` (onglet Inventaires) | `<i data-lucide="clipboard-check" class="icon"></i>` |
| `<i class="bi bi-list-ul" style="color:#4f46e5"></i>` | `<i data-lucide="list" class="icon" style="color:var(--accent)"></i>` |
| `<i class="bi bi-search"></i>` | `<i data-lucide="search" class="icon"></i>` |
| `<i class="bi bi-plus-lg"></i>` (×3) | `<i data-lucide="plus" class="icon"></i>` |
| `<i class="bi bi-box-arrow-in-down" style="color:#10b981"></i>` (modal entrée) | `<i data-lucide="package-plus" class="icon" style="color:var(--success)"></i>` |
| `<i class="bi bi-box-arrow-up" style="color:#f59e0b"></i>` (modal sortie) | `<i data-lucide="package-minus" class="icon" style="color:var(--warning)"></i>` |
| `<i class="bi bi-clipboard2-plus" style="color:#4f46e5"></i>` (modal inventaire) | `<i data-lucide="clipboard-plus" class="icon" style="color:var(--accent)"></i>` |
| `<i class="bi bi-x-lg"></i>` (×3) | `<i data-lucide="x" class="icon"></i>` |
| `<i class="bi bi-check-lg"></i>` (×3) | `<i data-lucide="check" class="icon"></i>` |

- [ ] **Step 5: Icônes générées dans le JS inline**

```javascript
    document.getElementById('stock-kpis').innerHTML = `
      <div class="kpi-card primary">
        <div class="kpi-icon"><i data-lucide="wallet" class="icon" style="color:var(--accent)"></i></div>
        <div class="kpi-label">Valeur stock (achat)</div>
        <div class="kpi-value">${fmt(stockData.totaux.valeur_achat)}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i data-lucide="boxes" class="icon" style="color:var(--accent)"></i></div>
        <div class="kpi-label">${stockData.totaux.nb_produits} produits</div>
        <div class="kpi-value">${stockData.normal.length} <small style="font-size:.9rem;color:var(--text-muted)">en stock</small></div>
        <div class="kpi-sub">${stockData.bas.length} stock bas · ${stockData.rupture.length} rupture</div>
      </div>
      <div class="kpi-card ${stockData.rupture.length > 0 ? 'danger' : ''}">
        <div class="kpi-icon"><i data-lucide="${stockData.rupture.length > 0 ? 'triangle-alert' : 'circle-check-big'}" class="icon" style="color:${stockData.rupture.length > 0 ? 'var(--danger)' : 'var(--success)'}"></i></div>
        <div class="kpi-label">Alertes</div>
        <div class="kpi-value">${stockData.rupture.length + stockData.bas.length}</div>
      </div>`;
    refreshIcons();

    renderStockTable(all);
```

(`refreshIcons()` ajouté ici car ce bloc précis est assigné directement, pas via `renderTable`.)

Pour les statuts d'inventaire, le bouton d'action :

```javascript
        <td>
          ${inv.status === 'en_cours'
            ? `<button class="btn btn-sm btn-primary" onclick="openInventoryCount(${inv.id})"><i data-lucide="square-pen" class="icon"></i> Compter</button>`
            : `<button class="btn btn-sm btn-outline" onclick="openInventoryDetail(${inv.id})"><i data-lucide="eye" class="icon"></i> Voir</button>`}
        </td>
```

- [ ] **Step 6: Vérification manuelle**

1. `stock.html` connecté en `admin` : 4 onglets (Vue Stock/Entrées/Sorties/Inventaires) fonctionnels, icônes visibles.
2. Vue Stock : KPI + tableau + recherche filtrent correctement, points de couleur (normal/bas/rupture) visibles.
3. Créer une entrée de stock test → apparaît dans l'onglet Entrées.
4. Créer une sortie test → apparaît dans l'onglet Sorties.
5. Onglet Inventaires : créer un inventaire "complet" → redirection vers `inventory-count.html?id=...` (page traitée en Task 8 — un 404 est normal tant que Task 8 n'est pas faite, mais ne doit pas empêcher stock.html lui-même de fonctionner).

- [ ] **Step 7: Commit**

```bash
git add frontend/stock.html
git commit -m "design: applique le nouveau design system a la page stock"
```

---

### Task 8: Page Inventaire (comptage)

**Files:**
- Modify: `frontend/inventory-count.html`

**Interfaces:**
- Consumes: `api.get('/inventories/{id}')`, `api.post('/inventories/{id}/count')`, `api.post('/inventories/{id}/validate')` (inchangé — livré au Sprint 1).

- [ ] **Step 1: `<head>`** — remplacer Bootstrap Icons par Lucide.

- [ ] **Step 2: Remplacer le bloc topbar+sidebar** par le bloc canonique de Task 3 (garder le `<div class="flex justify-between items-center" style="margin-bottom:22px">…</div>` propre à cette page tel quel après, avec son bouton Retour).

- [ ] **Step 3: Icônes statiques**

| Ancien | Nouveau |
|---|---|
| `<i class="bi bi-clipboard2-check"></i>` (page-icon) | `<i data-lucide="clipboard-check" class="icon"></i>` |
| `style="background:#059669"` | `style="background:var(--success)"` |
| `<i class="bi bi-arrow-left"></i>` (bouton Retour) | `<i data-lucide="arrow-left" class="icon"></i>` |
| `<i class="bi bi-list-ul" style="color:#4f46e5"></i>` | `<i data-lucide="list" class="icon" style="color:var(--accent)"></i>` |
| `<i class="bi bi-search"></i>` | `<i data-lucide="search" class="icon"></i>` |
| `<i class="bi bi-check-lg"></i>` (bouton Enregistrer comptage) | `<i data-lucide="check" class="icon"></i>` |
| `<i class="bi bi-shield-check"></i>` (bouton Valider) | `<i data-lucide="shield-check" class="icon"></i>` |

- [ ] **Step 4: Icônes générées dans le JS inline**

```javascript
    ecartKpis.innerHTML = `
      <div class="kpi-card ${inventory.nb_ecarts > 0 ? 'danger' : 'success'}">
        <div class="kpi-icon"><i data-lucide="triangle-alert" class="icon" style="color:${inventory.nb_ecarts > 0 ? 'var(--danger)' : 'var(--success)'}"></i></div>
        <div class="kpi-label">Écarts constatés</div>
        <div class="kpi-value">${inventory.nb_ecarts ?? 0}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i data-lucide="wallet" class="icon" style="color:var(--accent)"></i></div>
        <div class="kpi-label">Valeur de l'écart (achat)</div>
        <div class="kpi-value">${fmt(inventory.valeur_ecart_fcfa ?? 0)}</div>
      </div>`;
```

`renderItems()` utilise `renderTable()` → `refreshIcons()` déjà déclenché automatiquement (Task 2). Ajouter `refreshIcons();` juste après l'assignation de `inv-info-card.innerHTML` et `ecartKpis.innerHTML` dans `render()` (ces deux blocs sont assignés directement, pas via `renderTable`) :

```javascript
  document.getElementById('inv-info-card').innerHTML = `...`;
  refreshIcons();
```
et
```javascript
    ecartKpis.innerHTML = `...`;
    refreshIcons();
  } else {
    ecartKpis.classList.add('hidden');
  }
```

- [ ] **Step 5: Vérification manuelle** (reprend les 10 étapes du test manuel du Sprint 1, en vérifiant en plus que le rendu visuel correspond au nouveau design system) :

1. Depuis `stock.html`, créer un inventaire complet → arrivée sur `inventory-count.html?id=X` sans 404.
2. Vérifier icônes (clipboard, flèche retour, liste, recherche) visibles.
3. Saisir des quantités, "Enregistrer le comptage" → barre de progression se met à jour, icônes des lignes de statut correctes.
4. Compléter 100% du comptage → bouton "Valider l'inventaire" apparaît (rôle `proprietaire` uniquement).
5. Valider → passage en lecture seule, KPI écarts affichés avec icônes correctes.

- [ ] **Step 6: Commit**

```bash
git add frontend/inventory-count.html
git commit -m "design: applique le nouveau design system a la page inventaire"
```

---

### Task 9: Page Point de vente (POS)

**Files:**
- Modify: `frontend/pos.html`

**Interfaces:**
- Consumes: `api.get('/cash-sessions/current')`, `api.post('/cash-sessions/open|close')`, `api.get('/products')`, `api.get('/categories')`, `api.post('/sales')` (inchangé).

- [ ] **Step 1: `<head>`** — remplacer Bootstrap Icons par Lucide.

- [ ] **Step 2: Adapter les couleurs codées en dur dans le CSS local**

Remplacer dans le `<style>` de la page : `var(--primary)` → `var(--accent)` partout (le token `--primary` n'existe plus, remplacé par `--accent` dans Task 1), `#4f46e5`/`#7c3aed` (dégradé `.product-card::before`) → `linear-gradient(90deg, var(--accent), var(--accent-dark))`, `var(--primary-dark)` (dans `.summary-total`) → `var(--accent-dark)`, `#f8f9ff` (hover `.cart-item`) → `var(--border-soft)`, `#fafbff` (`.cart-summary` bg) → `var(--border-soft)`.

- [ ] **Step 3: Remplacer le bloc topbar+sidebar** par le bloc canonique de Task 3.

- [ ] **Step 4: Icônes statiques**

| Ancien | Nouveau |
|---|---|
| `<i class="bi bi-hourglass-split"></i>` | `<i data-lucide="hourglass" class="icon"></i>` |
| `<i class="bi bi-unlock-fill"></i>` (×2, ouvrir caisse) | `<i data-lucide="lock-open" class="icon"></i>` |
| `<i class="bi bi-lock-fill"></i>` (×3, fermer caisse) | `<i data-lucide="lock" class="icon"></i>` |
| `<i class="bi bi-search"></i>` | `<i data-lucide="search" class="icon"></i>` |
| `<i class="bi bi-cart3"></i>` (titre panier) | `<i data-lucide="shopping-cart" class="icon"></i>` |
| `<i class="bi bi-trash3"></i>` (×2, vider panier + suppr ligne) | `<i data-lucide="trash-2" class="icon"></i>` |
| `<i class="bi bi-cart-x"></i>` (panier vide) | `<i data-lucide="shopping-cart" class="icon"></i>` |
| `<i class="bi bi-tag-fill"></i>` (ligne remise) | `<i data-lucide="tag" class="icon"></i>` |
| `<i class="bi bi-percent"></i>` (bouton + modal remise) | `<i data-lucide="percent" class="icon"></i>` |
| `<i class="bi bi-credit-card-2-front-fill"></i>` (bouton Encaisser) | `<i data-lucide="credit-card" class="icon"></i>` |
| `<i class="bi bi-x-lg"></i>` (×4, fermetures modals) | `<i data-lucide="x" class="icon"></i>` |
| `<i class="bi bi-check-lg"></i>` (×2) | `<i data-lucide="check" class="icon"></i>` |
| `<i class="bi bi-credit-card-fill"></i>` (titre modal paiement) | `<i data-lucide="credit-card" class="icon"></i>` |

- [ ] **Step 5: Icônes générées dans le JS inline**

```javascript
    info.innerHTML = `<i data-lucide="circle-check-big" class="icon"></i> Caisse ouverte depuis ${escHtml(session.opened_at)} — Fonds : ${fmt(session.opening_amount)}`;
```
```javascript
    info.innerHTML = '<i data-lucide="circle-x" class="icon"></i> Caisse fermée — Ouvrez la caisse pour encaisser';
```
(ajouter `refreshIcons();` juste après ce bloc dans `updateSessionBanner()`, assigné directement.)

```javascript
    grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><div class="icon-wrap"><i data-lucide="search" class="icon"></i></div><p>Aucun produit trouvé</p></div>';
    refreshIcons();
    return;
```

```javascript
  grid.innerHTML = filtered.map(p => {
    const qty   = p.stock_quantity ?? 0;
    const price = saleType === 'gros'
      ? (p.prices?.wholesale_price ?? p.prices?.retail_price ?? 0)
      : (p.prices?.retail_price ?? 0);
    const out = qty <= 0;
    return `<div class="product-card${out ? ' out' : ''}" data-id="${p.id}" title="${escHtml(p.name)}">
      <span class="p-cat">${escHtml(p.category ?? '')}</span>
      <span class="p-name">${escHtml(p.name)}</span>
      <span class="p-price">${fmt(price)}</span>
      <span class="p-stock ${out ? 'rupture' : ''}">
        <i data-lucide="${out ? 'circle-x' : 'package'}" class="icon"></i>
        ${out ? 'Rupture' : `${qty} ${escHtml(p.unit)}`}
      </span>
    </div>`;
  }).join('');
  refreshIcons();
```

```javascript
    container.innerHTML = '<div class="cart-empty"><div class="icon-wrap"><i data-lucide="shopping-cart" class="icon"></i></div><p>Cliquez sur un produit pour l\'ajouter</p></div>';
    summary.style.display = 'none';
    document.getElementById('btn-pay').disabled = true;
    refreshIcons();
    return;
```

```javascript
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
```

Dans `openPayment()` :
```javascript
  document.getElementById('payment-title').innerHTML = '<i data-lucide="credit-card" class="icon"></i> Confirmer l\'encaissement';
  document.getElementById('payment-body').innerHTML = `
    <div class="alert alert-success mb-4">
      <i data-lucide="circle-check-big" class="icon"></i>
      <div>Total à encaisser : <strong>${fmt(total)}</strong></div>
    </div>
    ...`;
  document.getElementById('payment-footer').innerHTML = `
    <button class="btn btn-outline" data-dismiss>Annuler</button>
    <button class="btn btn-accent btn-lg" id="btn-confirm-pay">
      <i data-lucide="circle-check-big" class="icon"></i> Encaisser ${fmt(total)}
    </button>
  `;
  refreshIcons();
```

Dans `processSale()` catch, et dans `showReceipt()` (titre + icônes du reçu + bouton fermer) :
```javascript
    btn.innerHTML = `<i data-lucide="credit-card" class="icon"></i> Encaisser ${fmt(total)}`;
    refreshIcons();
```
```javascript
  document.getElementById('payment-title').innerHTML = '<i data-lucide="receipt" class="icon"></i> Reçu';
  document.getElementById('payment-body').innerHTML = `
    <div class="receipt">
      <h3><i data-lucide="shopping-bag" class="icon"></i> Boutique D</h3>
      ...`;
  document.getElementById('payment-footer').innerHTML = `
    <button class="btn btn-primary btn-block" data-dismiss>
      <i data-lucide="check" class="icon"></i> Fermer
    </button>`;
  openModal('modal-payment');
  refreshIcons();
  toast('Vente enregistrée avec succès !', 'success');
```

- [ ] **Step 6: Vérification manuelle**

1. Se connecter en `admin`, aller sur `pos.html`.
2. Si caisse fermée : ouvrir avec un fonds de 50000 → bandeau passe au vert.
3. Chercher/filtrer un produit, l'ajouter au panier (clic sur carte produit) → apparaît dans le panier avec les bons contrôles +/−.
4. Appliquer une remise 10% → ligne remise visible dans le résumé.
5. Encaisser en espèces avec un montant reçu suffisant → reçu affiché avec toutes les icônes correctes, vente enregistrée (`toast` succès), panier vidé, stock des produits mis à jour.
6. Fermer la caisse avec un montant compté → bandeau repasse en rouge.

- [ ] **Step 7: Commit**

```bash
git add frontend/pos.html
git commit -m "design: applique le nouveau design system a la page pos"
```

---

### Task 10: Page Rapports

**Files:**
- Modify: `frontend/reports.html`

**Interfaces:**
- Consumes: `api.get('/reports/sales|treasury|stock|employees')` (inchangé).

- [ ] **Step 1: `<head>`** — remplacer Bootstrap Icons par Lucide.

- [ ] **Step 2: Adapter `.tab-btn.active`** — même changement que Task 7 Step 2 (dégradé → `var(--accent)`).

- [ ] **Step 3: Remplacer le bloc topbar+sidebar** par le bloc canonique de Task 3 — **attention** : la sidebar actuelle de `reports.html` n'a **aucun** `data-role` sur ses `<a>` (contrairement aux autres pages) ; le bloc canonique en ajoute (cohérence voulue, cf. note Task 3).

- [ ] **Step 4: Icônes statiques**

| Ancien | Nouveau |
|---|---|
| `<i class="bi bi-bar-chart-fill"></i>` (page-icon) | `<i data-lucide="chart-column" class="icon"></i>` |
| `style="background:#f59e0b"` (page-icon) | `style="background:var(--warning)"` |
| `<i class="bi bi-cash-coin"></i>` (onglet Ventes) | `<i data-lucide="hand-coins" class="icon"></i>` |
| `<i class="bi bi-bank2"></i>` (onglet Trésorerie) | `<i data-lucide="landmark" class="icon"></i>` |
| `<i class="bi bi-boxes"></i>` (onglet Stock) | `<i data-lucide="boxes" class="icon"></i>` |
| `<i class="bi bi-people-fill"></i>` (onglet Employés) | `<i data-lucide="users" class="icon"></i>` |
| `<i class="bi bi-arrow-clockwise"></i>` (×3, boutons Actualiser) | `<i data-lucide="refresh-cw" class="icon"></i>` |
| `<i class="bi bi-list-ul" style="color:#4f46e5"></i>` | `<i data-lucide="list" class="icon" style="color:var(--accent)"></i>` |
| `<i class="bi bi-cash-register" style="color:#4f46e5"></i>` | `<i data-lucide="store" class="icon" style="color:var(--accent)"></i>` |
| `<i class="bi bi-box-arrow-in-down" style="color:#10b981"></i>` | `<i data-lucide="package-plus" class="icon" style="color:var(--success)"></i>` |
| `<i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b"></i>` | `<i data-lucide="triangle-alert" class="icon" style="color:var(--warning)"></i>` |

- [ ] **Step 5: Icônes générées dans le JS inline**

`loadSales()` :
```javascript
    document.getElementById('sales-kpis').innerHTML = `
      <div class="kpi-card primary">
        <div class="kpi-icon"><i data-lucide="wallet" class="icon"></i></div>
        <div class="kpi-label">CA Total</div>
        <div class="kpi-value">${fmt(d.resume.total_ca)}</div>
        <div class="kpi-sub">${escHtml(d.periode.debut)} — ${escHtml(d.periode.fin)}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i data-lucide="shopping-cart" class="icon" style="color:var(--warning)"></i></div>
        <div class="kpi-label">Ventes</div>
        <div class="kpi-value">${d.resume.nb_ventes}</div>
        <div class="kpi-sub">Panier moy. ${fmt(d.resume.panier_moyen)}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i data-lucide="banknote" class="icon" style="color:var(--success)"></i></div>
        <div class="kpi-label">Espèces</div>
        <div class="kpi-value">${fmt(d.par_paiement.especes.total)}</div>
        <div class="kpi-sub">${d.par_paiement.especes.count} transaction(s)</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i data-lucide="smartphone" class="icon" style="color:var(--accent)"></i></div>
        <div class="kpi-label">Mobile Money</div>
        <div class="kpi-value">${fmt(d.par_paiement.mobile_money.total)}</div>
        <div class="kpi-sub">${d.par_paiement.mobile_money.count} transaction(s)</div>
      </div>`;
    refreshIcons();

    renderTable('sales-tbody', d.ventes.map(s => `
      <tr>
        <td class="font-bold text-primary">${escHtml(s.receipt_number)}</td>
        <td>${escHtml(s.date)}</td>
        <td>${escHtml(s.cashier ?? '—')}</td>
        <td>
          <span class="badge ${s.sale_type === 'gros' ? 'badge-accent' : 'badge-neutral'}">
            ${s.sale_type === 'gros' ? 'Gros' : 'Détail'}
          </span>
        </td>
        <td>
          <span class="badge badge-neutral">
            <i data-lucide="${s.payment_method === 'especes' ? 'banknote' : 'smartphone'}" class="icon"></i>
            ${s.payment_method === 'especes' ? 'Espèces' : 'Mobile'}
          </span>
        </td>
        <td class="text-right">${s.nb_articles}</td>
        <td class="text-right font-bold">${fmt(s.total)}</td>
      </tr>`), 'Aucune vente sur cette période');
```

`loadTreasury()` :
```javascript
    document.getElementById('treasury-kpis').innerHTML = `
      <div class="kpi-card primary">
        <div class="kpi-icon"><i data-lucide="vault" class="icon"></i></div>
        <div class="kpi-label">Total encaissé</div>
        <div class="kpi-value">${fmt(d.encaissements.total)}</div>
        <div class="kpi-sub">${escHtml(d.periode.debut)} — ${escHtml(d.periode.fin)}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i data-lucide="trending-up" class="icon" style="color:var(--success)"></i></div>
        <div class="kpi-label">Net (après remboursements)</div>
        <div class="kpi-value">${fmt(d.encaissements.net)}</div>
        <div class="kpi-sub">Remboursements : ${fmt(d.encaissements.remboursements)}</div>
      </div>
      <div class="kpi-card ${d.sessions.avec_ecart > 0 ? 'accent' : ''}">
        <div class="kpi-icon"><i data-lucide="${d.sessions.avec_ecart > 0 ? 'triangle-alert' : 'circle-check-big'}" class="icon"></i></div>
        <div class="kpi-label">Sessions avec écart</div>
        <div class="kpi-value">${d.sessions.avec_ecart} / ${d.sessions.total}</div>
        <div class="kpi-sub">Écart total : ${fmt(d.sessions.total_ecarts)}</div>
      </div>`;
    refreshIcons();
```

`loadStockReport()` :
```javascript
    document.getElementById('stock-kpis').innerHTML = `
      <div class="kpi-card primary">
        <div class="kpi-icon"><i data-lucide="archive" class="icon"></i></div>
        <div class="kpi-label">Valeur stock (achat)</div>
        <div class="kpi-value">${fmt(d.valeur_stock.achat)}</div>
        <div class="kpi-sub">${d.valeur_stock.nb_produits} produits</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i data-lucide="tags" class="icon" style="color:var(--warning)"></i></div>
        <div class="kpi-label">Valeur (vente)</div>
        <div class="kpi-value">${fmt(d.valeur_stock.vente)}</div>
        <div class="kpi-sub">Marge ${d.valeur_stock.marge_pct}%</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i data-lucide="trending-up" class="icon" style="color:var(--success)"></i></div>
        <div class="kpi-label">Marge brute</div>
        <div class="kpi-value">${fmt(d.valeur_stock.marge_brute)}</div>
      </div>`;
    refreshIcons();

    ...

    document.getElementById('stock-alerts-body').innerHTML = alerts.length
      ? alerts.map(a => `
          <div class="flex justify-between items-center mb-2">
            <span>${escHtml(a.name)}</span>
            <span class="badge ${a.quantity === undefined ? 'badge-danger' : 'badge-warning'}">
              ${a.quantity === undefined ? '<i data-lucide="circle-x" class="icon"></i> Rupture' : `${a.quantity} restant`}
            </span>
          </div>`).join('')
      : '<div class="empty-state" style="padding:24px"><div class="icon-wrap"><i data-lucide="circle-check-big" class="icon" style="color:var(--success)"></i></div><p>Aucune alerte</p></div>';
    refreshIcons();
```

`loadEmployees()` : pas d'icône Bootstrap dans les lignes (juste un avatar de lettre), aucune modification nécessaire au-delà de `renderTable()` qui gère déjà `refreshIcons()`.

- [ ] **Step 6: Vérification manuelle**

1. `reports.html` connecté en `admin` (seul rôle avec accès, cf. note Task 3).
2. Onglet Ventes : filtrer par période "Cette semaine", vérifier KPI + tableau se mettent à jour, icônes espèces/mobile correctes par ligne.
3. Onglet Trésorerie : vérifier KPI + tableau des sessions.
4. Onglet Stock : vérifier KPI + entrées du mois + alertes.
5. Onglet Employés : vérifier tableau (avatars, badges rôle).

- [ ] **Step 7: Commit**

```bash
git add frontend/reports.html
git commit -m "design: applique le nouveau design system a la page rapports"
```

---

### Task 11: Page Utilisateurs

**Files:**
- Modify: `frontend/users.html`

**Interfaces:**
- Consumes: `api.get/post/put/patch('/users...')` (inchangé).

- [ ] **Step 1: `<head>`** — remplacer Bootstrap Icons par Lucide.

- [ ] **Step 2: Remplacer le bloc topbar+sidebar** par le bloc canonique de Task 3 (même remarque `data-role` que Task 10).

- [ ] **Step 3: Icônes statiques**

| Ancien | Nouveau |
|---|---|
| `<i class="bi bi-people-fill"></i>` (page-icon) | `<i data-lucide="users" class="icon"></i>` |
| `<i class="bi bi-search"></i>` | `<i data-lucide="search" class="icon"></i>` |
| `<i class="bi bi-person-plus-fill"></i>` (×2, bouton + titre modal) | `<i data-lucide="user-plus" class="icon"></i>` |
| `<i class="bi bi-x-lg"></i>` (×2) | `<i data-lucide="x" class="icon"></i>` |
| `<i class="bi bi-check-lg"></i>` (×2) | `<i data-lucide="check" class="icon"></i>` |
| `<i class="bi bi-key-fill" style="color:#f59e0b"></i>` (titre modal reset) | `<i data-lucide="key" class="icon" style="color:var(--warning)"></i>` |

Couleurs codées en dur à remplacer par les tokens dans le markup et le JS : `#4f46e5` → `var(--accent)`, `#f1f5f9` → `var(--border-soft)`.

- [ ] **Step 4: Icônes générées dans le JS inline**

```javascript
        <td>
          <span class="badge ${u.is_active ? 'badge-success' : 'badge-danger'}">
            <span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block"></span>
            ${u.is_active ? 'Actif' : 'Inactif'}
          </span>
          ${u.is_locked ? '<span class="badge badge-danger ml-1"><i data-lucide="lock" class="icon"></i> Bloqué</span>' : ''}
        </td>
        <td class="text-muted font-sm">${escHtml(u.last_login_at ?? '—')}</td>
        <td>
          <div style="display:flex;gap:6px">
            <button class="btn btn-sm btn-outline" onclick="editUser(${u.id})" title="Modifier">
              <i data-lucide="pencil" class="icon"></i>
            </button>
            <button class="btn btn-sm btn-ghost" onclick="toggleUser(${u.id})" title="${u.is_active ? 'Désactiver' : 'Activer'}">
              <i data-lucide="${u.is_active ? 'toggle-right' : 'toggle-left'}" class="icon"></i>
            </button>
            <button class="btn btn-sm btn-ghost" onclick="resetPwd(${u.id})" title="Réinitialiser mot de passe">
              <i data-lucide="key" class="icon" style="color:var(--warning)"></i>
            </button>
          </div>
        </td>
```

(Le point de statut `<i class="bi bi-circle-fill">` devient un point CSS pur, cohérent avec le traitement pill de Task 5/9.)

Dans `editUser()` :
```javascript
  document.getElementById('user-modal-title').innerHTML = '<i data-lucide="square-pen" class="icon" style="color:var(--accent)"></i> Modifier utilisateur';
  openModal('modal-user');
  refreshIcons();
```

Dans le handler "Nouvel utilisateur" :
```javascript
  document.getElementById('user-modal-title').innerHTML = '<i data-lucide="user-plus" class="icon" style="color:var(--accent)"></i> Nouvel utilisateur';
  openModal('modal-user');
  refreshIcons();
```

L'avatar-lettre (`background:#4f46e5`) devient `background:var(--accent)` (×2 occurrences : liste utilisateurs et modal si présent).

- [ ] **Step 5: Vérification manuelle**

1. `users.html` connecté en `admin`.
2. Rechercher un utilisateur → filtre fonctionne.
3. Créer un utilisateur test (rôle `vendeur`) → apparaît dans la liste avec badge rôle correct.
4. Toggle actif/inactif → icône change (toggle-left/right), badge Actif/Inactif change.
5. Réinitialiser le mot de passe d'un utilisateur test → modal s'ouvre, confirmation, toast succès.
6. Modifier un utilisateur → champ mot de passe masqué (comportement existant conservé), modal pré-rempli.

- [ ] **Step 6: Commit**

```bash
git add frontend/users.html
git commit -m "design: applique le nouveau design system a la page utilisateurs"
```

---

### Task 12: Nouvelle page Profil

**Files:**
- Create: `frontend/profile.html`

**Interfaces:**
- Consumes: `api.get('/auth/me')` (existant, jamais utilisé par une UI), `api.put('/auth/password', {current_password, password, password_confirmation})` (existant — vérifier la signature exacte du endpoint avant d'écrire le JS, voir Step 1).

- [ ] **Step 1: Vérifier la signature exacte de `PUT /auth/password`**

Lire `backend/app/Http/Controllers/Api/AuthController.php`, méthode `changePassword` (autour de la ligne 82-100 d'après l'audit du 2026-07-13), pour confirmer les noms de champs attendus dans le body (probablement `current_password`, `password`, `password_confirmation` vu `'password' => 'hashed'` et la règle `min:6|confirmed` mentionnée dans l'audit). Adapter le JS du Step 3 aux noms réels trouvés. Ne pas deviner : ouvrir le fichier et lire le `Request::validate()` de cette méthode.

- [ ] **Step 2: Créer `frontend/profile.html`**

```html
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Profil — Boutique D</title>
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#2563EB">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@0.462.0/dist/umd/lucide.js" integrity="sha384-WBRt9V/J/erVtkEuP91HUFRv9MvHzFiFOp4/zTDp4xkcMG7aOeIv2asTV4yxFLWa" crossorigin="anonymous"></script>
  <link rel="stylesheet" href="css/app.css">
  <style>
    .profile-card { max-width: 520px; }
    .profile-header { display:flex; align-items:center; gap:14px; padding:20px; border-bottom:1px solid var(--border-soft); }
    .profile-avatar { width:52px; height:52px; border-radius:50%; background:linear-gradient(150deg,var(--accent),#1E3A8A); color:white; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.1rem; flex-shrink:0; }
    .profile-name { font-weight:700; font-size:1rem; }
    .profile-role { font-size:.8rem; color:var(--text-muted); }
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
      <span class="nav-icon-wrap"><i data-lucide="layout-grid" class="icon"></i></span> Dashboard
    </a>
    <a href="pos.html" class="nav-item">
      <span class="nav-icon-wrap"><i data-lucide="store" class="icon"></i></span> Caisse (POS)
    </a>
    <div class="sidebar-section" data-role="proprietaire,gestionnaire">Stock</div>
    <a href="stock.html" class="nav-item" data-role="proprietaire,gestionnaire">
      <span class="nav-icon-wrap"><i data-lucide="boxes" class="icon"></i></span> Stock
      <span class="nav-badge hidden" id="alert-badge">0</span>
    </a>
    <a href="products.html" class="nav-item" data-role="proprietaire,gestionnaire">
      <span class="nav-icon-wrap"><i data-lucide="tag" class="icon"></i></span> Produits
    </a>
    <div class="sidebar-section" data-role="proprietaire">Gestion</div>
    <a href="users.html" class="nav-item" data-role="proprietaire">
      <span class="nav-icon-wrap"><i data-lucide="users" class="icon"></i></span> Utilisateurs
    </a>
    <a href="reports.html" class="nav-item" data-role="proprietaire">
      <span class="nav-icon-wrap"><i data-lucide="chart-column" class="icon"></i></span> Rapports
    </a>
    <div class="sidebar-section">Compte</div>
    <a href="profile.html" class="nav-item">
      <span class="nav-icon-wrap"><i data-lucide="user" class="icon"></i></span> Profil
    </a>
  </nav>

  <main class="main">
    <div class="page-title">
      <div class="page-icon" style="background:var(--accent)">
        <i data-lucide="user" class="icon"></i>
      </div>
      Mon profil
    </div>

    <div class="card profile-card mb-6">
      <div class="profile-header">
        <div class="profile-avatar" id="profile-avatar"></div>
        <div>
          <div class="profile-name" id="profile-name">—</div>
          <div class="profile-role" id="profile-role">—</div>
        </div>
      </div>
      <div class="card-body">
        <div class="grid grid-2 gap-3">
          <div class="form-group">
            <label class="form-label">Identifiant</label>
            <input class="form-control" id="profile-username" disabled>
          </div>
          <div class="form-group">
            <label class="form-label">Téléphone</label>
            <input class="form-control" id="profile-phone" disabled>
          </div>
        </div>
      </div>
    </div>

    <div class="card profile-card">
      <div class="card-header">
        <div class="card-title"><i data-lucide="key" class="icon"></i> Changer le mot de passe</div>
      </div>
      <div class="card-body">
        <div class="form-group mb-4">
          <label class="form-label">Mot de passe actuel *</label>
          <input class="form-control" id="pwd-current" type="password" placeholder="••••••••">
        </div>
        <div class="grid grid-2 gap-3 mb-4">
          <div class="form-group">
            <label class="form-label">Nouveau mot de passe *</label>
            <input class="form-control" id="pwd-new" type="password" placeholder="Min. 6 caractères">
          </div>
          <div class="form-group">
            <label class="form-label">Confirmer *</label>
            <input class="form-control" id="pwd-confirm" type="password" placeholder="Min. 6 caractères">
          </div>
        </div>
        <button class="btn btn-primary" id="btn-change-pwd">
          <i data-lucide="check" class="icon"></i> Mettre à jour le mot de passe
        </button>
      </div>
    </div>
  </main>
</div>

<script src="js/api.js"></script>
<script src="js/app.js"></script>
<script>
requireAuth();

async function loadProfile() {
  try {
    const me = await api.get('/auth/me');
    document.getElementById('profile-avatar').textContent = me.name.charAt(0).toUpperCase();
    document.getElementById('profile-name').textContent = me.name;
    document.getElementById('profile-role').textContent = me.role;
    document.getElementById('profile-username').value = me.username ?? '';
    document.getElementById('profile-phone').value = me.phone ?? '—';
  } catch (err) { toast(err.message, 'danger'); }
}

document.getElementById('btn-change-pwd').addEventListener('click', async () => {
  const current  = document.getElementById('pwd-current').value;
  const pwd      = document.getElementById('pwd-new').value;
  const confirm_ = document.getElementById('pwd-confirm').value;

  if (!current || !pwd || !confirm_) { toast('Remplissez tous les champs', 'warning'); return; }
  if (pwd.length < 6) { toast('Le nouveau mot de passe doit faire au moins 6 caractères', 'warning'); return; }
  if (pwd !== confirm_) { toast('Les mots de passe ne correspondent pas', 'warning'); return; }

  try {
    await api.put('/auth/password', {
      current_password: current,
      password: pwd,
      password_confirmation: confirm_,
    });
    toast('Mot de passe mis à jour', 'success');
    ['pwd-current', 'pwd-new', 'pwd-confirm'].forEach(id => document.getElementById(id).value = '');
  } catch (err) { toast(err.message, 'danger'); }
});

loadProfile();
</script>
</body>
</html>
```

**Note d'implémentation :** les noms de champs `current_password`/`password`/`password_confirmation` sont une hypothèse basée sur l'audit — **Step 1 ci-dessus doit les confirmer ou les corriger** avant de considérer cette tâche terminée.

- [ ] **Step 3: Vérification manuelle**

1. Se connecter, aller sur `profile.html` (nouveau lien sidebar "Profil").
2. Vérifier que nom, rôle, identifiant s'affichent correctement (issus de `GET /auth/me`).
3. Tenter de changer le mot de passe avec un mauvais mot de passe actuel → message d'erreur du backend affiché.
4. Changer le mot de passe avec les bonnes valeurs → toast succès, champs vidés.
5. Se déconnecter, se reconnecter avec le nouveau mot de passe → doit fonctionner (puis le remettre à `admin123` pour ne pas casser les identifiants de dev connus, ou noter le changement).

- [ ] **Step 4: Commit**

```bash
git add frontend/profile.html
git commit -m "design: ajoute la page Profil (branchee sur PUT /auth/password existant)"
```

---

### Task 13: Passe finale d'harmonisation

**Files:**
- Modify: potentiellement tous les fichiers `frontend/*.html` si des incohérences sont trouvées.

- [ ] **Step 1: Revue croisée**

Parcourir les 8 pages consécutivement dans le navigateur (desktop, puis réduire à 900px et 560px pour vérifier le responsive) et vérifier :
- Même hauteur/style de sidebar et topbar partout.
- Même style de boutons primaire/secondaire partout.
- Aucune icône Bootstrap résiduelle : `grep -rn "bi bi-\|class=\"bi " frontend/*.html` doit ne rien retourner en dehors de commentaires éventuels.
- Aucune couleur codée en dur résiduelle de l'ancien design (`#4f46e5`, `#7c3aed`, `#f59e0b` utilisés comme couleur de marque plutôt que comme token) : `grep -rn "#4f46e5\|#7c3aed" frontend/*.html frontend/css/app.css`.
- Le rail d'icônes sous 900px fonctionne sur les 8 pages (menu hamburger ouvre/ferme la sidebar en overlay).

- [ ] **Step 2: Corriger les écarts trouvés** (inline, pas de nouvelle tâche formelle — petits ajustements CSS/markup uniquement, toujours sans toucher au JS métier).

- [ ] **Step 3: Test de régression fonctionnelle complet**

Reprendre le parcours "tests manuels" de chaque tâche (4 à 12) une dernière fois d'affilée sur un même compte, pour confirmer qu'aucune régression n'a été introduite par les corrections d'harmonisation.

- [ ] **Step 4: Commit final**

```bash
git add -A
git commit -m "design: passe d'harmonisation finale du design system sur toute l'application"
```
