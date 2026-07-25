# Phase B6 — Synchronisation offline/online — Design

**Date :** 2026-07-25
**Statut :** Validé par le client (2026-07-25)

## Contexte

Le cahier des charges (Module 8) demande une capacité offline : IndexedDB, bandeau de connectivité, notifications, synchronisation au retour du réseau. L'état actuel du PWA est minimal : le service worker (`frontend/sw.js`) met en cache le shell statique (HTML/CSS/JS) mais **ignore totalement** les appels `/api/` (`if (url.pathname.startsWith('/api/')) return;`). Aucun IndexedDB, aucun bandeau, aucune file d'attente. Une coupure réseau pendant que la boutique est ouverte fait perdre des ventes — c'est le problème que ce module résout.

## Décisions de cadrage (2026-07-25)

- **Périmètre v1 : vente au comptoir uniquement.** Seule la caisse (POS) fonctionne hors-ligne (consulter le catalogue en cache, encaisser en espèces, mettre la vente en file). Le reste de l'application reste bloqué en cas de coupure, le bandeau prévenant l'utilisateur. L'inventaire hors-ligne, la vente tablette hors-ligne, etc. sont hors scope.
- **Paiement hors-ligne : espèces uniquement.** Le mobile money nécessite une confirmation réseau au moment de la transaction ; hors-ligne, le sélecteur mobile money est désactivé avec une explication.
- **Conflits de stock : résolution manuelle.** Au retour du réseau, la synchronisation rejoue `POST /sales` qui revérifie le stock (logique existante inchangée). Si le stock ne suffit plus (produit vendu entre-temps ailleurs), cette vente précise est marquée « échec — à traiter » et attend une décision humaine (Réessayer / Abandonner). Les autres ventes continuent de se synchroniser. **Le stock backend n'est jamais négatif.**
- **Déclenchement de la synchro : automatique (événement `online`) + bouton manuel** dans le bandeau.
- **Notifications : alertes dans l'app** (bandeau de connectivité + toasts existants), pas de vraies notifications push navigateur (pas de VAPID, pas d'abonnement push — hors scope).

## Contraintes non négociables

- **Un seul changement backend :** la protection anti-doublon par UUID sur `POST /sales` (voir §Backend). Tout le reste du module est frontend.
- **`sw.js` inchangé** : il continue d'ignorer `/api/`. Tout le cache applicatif de données passe par IndexedDB, jamais par le cache HTTP du service worker (évite deux couches de cache divergentes).
- **`frontend/js/app.js` et `frontend/js/api.js` inchangés.** Le nouveau code vit dans un fichier autonome `frontend/js/offline.js`.
- La logique de vente critique du backend (`SaleController::store()`) n'est modifiée **que** par l'ajout de la déduplication UUID, de façon additive (voir §Backend). Aucune autre régression.
- Montants entiers FCFA. Conventions de réponse (`{success,message,data}`) et de validation identiques au reste de l'application.

## Architecture

Nouveau fichier **`frontend/js/offline.js`**, chargé sur les 10 pages (après `api.js`/`app.js`), entièrement autonome : il s'initialise seul (`DOMContentLoaded`), sans dépendre de `app.js`. Il porte quatre responsabilités, chacune une section de code claire :

1. **Détection de connectivité** — écoute `online`/`offline` du navigateur, expose l'état courant, déclenche la synchro au retour du réseau.
2. **Bandeau** — une barre injectée en haut de chaque page, mise à jour selon l'état (en ligne / hors ligne / ventes en file / synchro en cours).
3. **Stockage IndexedDB** — helpers d'accès à la base `boutique-d-offline` (voir §Schéma).
4. **Moteur de synchronisation** — rejoue la file `pending_sales` une par une via `POST /sales`.

`pos.html` consomme `offline.js` pour brancher le chemin « vente hors-ligne » dans `processSale()` et pour rafraîchir le cache catalogue à chaque chargement en ligne.

## Schéma IndexedDB

Base **`boutique-d-offline`**, version 1, trois object stores :

- **`cache`** (keyPath `key`) — catalogue nécessaire pour vendre hors-ligne. Trois entrées : `{key:'products', value:[...]}`, `{key:'categories', value:[...]}`, `{key:'session', value:{...}}`. Écrites à chaque chargement réussi de `pos.html` en ligne. Le stock de chaque produit y est décrémenté localement au fil des ventes hors-ligne (indicatif — la vérité reste le backend).
- **`pending_sales`** (keyPath `local_id`, autoIncrement) — la file d'attente. Une entrée par vente encaissée hors-ligne :
  ```
  {
    local_id:   <auto>,          // clé locale
    uuid:       <string>,        // UUID v4 généré client, envoyé dans le body — clé de dédup
    body:       {...},           // corps exact du POST /sales (sale_type, payment_method:'especes', amount_paid, items, discount_*)
    created_at: <ISO string>,    // horodatage local
    status:     'pending' | 'syncing' | 'failed',
    error:      <string|null>,   // message backend si failed
    display:    {...},           // snapshot pour l'UI de résolution : items[{name, qty, total}], total
  }
  ```
- **`meta`** (keyPath `key`) — divers : `{key:'last_sync', value:<ISO>}`.

## Flux de vente hors-ligne

Dans `pos.html`, `processSale()` : si `!navigator.onLine`, au lieu de `POST /sales` :
1. Générer un `uuid` (v4, via `crypto.randomUUID()`).
2. Écrire la vente dans `pending_sales` (status `pending`), avec le `body` identique à celui d'une vente en ligne **plus le champ `uuid`**.
3. Décrémenter le stock des produits concernés dans le cache IndexedDB (`cache` → `products`).
4. Afficher le reçu comme d'habitude (avec une mention « À synchroniser »), vider le panier, rafraîchir la grille depuis le cache local.

Le sélecteur de paiement est verrouillé sur « espèces » quand `!navigator.onLine` (mobile money grisé + hint). Le bouton Encaisser reste actif hors-ligne (contrairement à aujourd'hui où il dépend d'une session chargée en ligne — hors-ligne, la session lue depuis le cache suffit).

## Flux de synchronisation

Déclenché par l'événement `online` **et** par le bouton « Synchroniser » du bandeau. Rejoue les ventes `pending`/`failed`-relancées **une par une, dans l'ordre de création** :

Pour chaque vente (passée en `syncing` le temps de l'appel) :
- **201 Created** → retirée de la file (`pending_sales.delete(local_id)`), compteur « synchronisées » +1, `meta.last_sync` mis à jour.
- **422 (stock insuffisant, ou toute erreur de validation métier)** → status `failed`, `error` = message backend, **reste dans la file**, toast « 1 vente à traiter ». Pas de rejeu automatique.
- **Erreur réseau (fetch rejette)** → on **arrête** la boucle (connexion repartie), la vente reste `pending`, nouvel essai au prochain événement `online` ou clic manuel.
- **401 (token expiré)** → on arrête, la vente reste `pending` (l'utilisateur sera redirigé vers login par le handler existant de `api.js` ; la file persiste en IndexedDB et sera reprise après reconnexion).

À la fin d'une passe : toast récapitulatif (« 3 vente(s) synchronisée(s) », et/ou « 1 à traiter »).

## Backend — déduplication UUID (seul changement backend)

`POST /sales` accepte un champ optionnel `uuid` (`nullable|uuid`). La table `sales` gagne une colonne `sync_uuid` (nullable, unique). Dans `store()`, **avant** toute création : si `uuid` est fourni et qu'une vente avec ce `sync_uuid` existe déjà, retourner cette vente existante (formatée via `formatSale()`) avec un `200` au lieu d'en créer une seconde. Sinon, créer normalement en stockant `sync_uuid`.

Cela garantit qu'un rejeu (synchro interrompue après création backend mais avant réception de la réponse côté client) est idempotent : la deuxième tentative reçoit la vente déjà créée, la retire de la file, sans doublon. Les ventes en ligne normales (sans `uuid`) sont inchangées.

**Additif et sûr :** `store()` gagne un `if` en tête et un champ de plus à la création ; aucune autre ligne modifiée. La colonne est nullable → zéro impact sur les ventes existantes.

## Interface

**Bandeau de connectivité** (injecté par `offline.js`, présent sur toutes les pages) :
- En ligne, aucune vente `pending` ni `failed` → masqué.
- Hors ligne → barre orange « Hors ligne — les ventes seront synchronisées au retour du réseau ».
- Au moins une vente `pending` (en ligne ou non) → barre bleue « X vente(s) en attente · [Synchroniser] », où X = nombre de ventes `pending` uniquement.
- Au moins une vente `failed` → segment rouge « Y à traiter · [Voir] » ouvrant la section de résolution. `pending` et `failed` sont comptés séparément (une vente `failed` n'est pas « en attente », elle attend une décision humaine).
- Pendant la synchro → « Synchronisation… ».

**Ventes à traiter** (échecs `failed`) — section dépliable depuis le bandeau, une ligne par vente : n° local, articles (depuis `display`), total, message backend. Deux actions : **Réessayer** (repasse la vente en `pending` et relance une synchro) ou **Abandonner** (supprime de la file). Aucune resynchronisation silencieuse d'une vente `failed`.

## Tests

**Backend (PHPUnit) :**
- `POST /sales` avec un `uuid` déjà utilisé retourne la vente existante (200) sans créer de doublon (compte `sales` inchangé).
- `POST /sales` avec un `uuid` neuf crée normalement et persiste `sync_uuid`.
- `POST /sales` sans `uuid` (vente en ligne classique) inchangé.
- Non-régression : la suite existante (47 tests) reste verte.

**Frontend (vérification navigateur, Chrome DevTools — pas de framework JS dans ce projet) :**
- Passer hors ligne (DevTools) → encaisser une vente espèces → vérifier : vente dans `pending_sales`, stock local décrémenté, bandeau orange, reçu affiché.
- Repasser en ligne → synchro automatique → vérifier : vente créée en base, file vidée, bandeau masqué, toast récapitulatif.
- Scénario conflit : créer une vente hors-ligne, réduire le stock backend en dessous (autre session) avant de repasser en ligne → vérifier : vente `failed`, section « à traiter », message correct ; réapprovisionner puis Réessayer → succès.
- Idempotence : simuler un rejeu du même `uuid` → une seule vente en base.

## Découpage en unités

- **`frontend/js/offline.js`** — module autonome, quatre sections internes (connectivité, bandeau, IndexedDB, synchro). Point d'entrée unique auto-initialisé. Expose sur `window` le minimum consommé par `pos.html` (`offlineSaveSale`, `offlineGetCachedProducts`, `offlineCacheCatalog`, `offlineIsOnline`).
- **`pos.html`** — branche le chemin hors-ligne dans `processSale()` + rafraîchit le cache à chaque `loadProducts()` réussi en ligne. Modifications ciblées, la logique de vente en ligne existante inchangée.
- **Backend** — migration `sync_uuid` + dédup dans `store()` + tests.

## Critères de succès

- Une vente encaissée hors-ligne n'est jamais perdue : elle réapparaît et se synchronise au retour du réseau.
- Le stock backend n'est jamais négatif ; un conflit est signalé, jamais silencieusement écrasé.
- Aucun doublon même en cas de synchro interrompue (dédup UUID).
- Zéro régression sur la vente en ligne classique et sur les 47 tests existants.
