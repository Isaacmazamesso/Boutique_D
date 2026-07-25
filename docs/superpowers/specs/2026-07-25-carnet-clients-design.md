# Carnet de clients — Design

**Date :** 2026-07-25
**Statut :** Validé par le client (2026-07-25)

## Contexte

Première brique du futur chantier « Clients » (préfiguration du Module 6 du cahier des charges, jusqu'ici hors périmètre v1). Aucune notion de client n'existe aujourd'hui : les 25 tables couvrent produits, ventes, stock, caisse, utilisateurs, mais pas les clients en tant qu'entité. Ce module pose la **fondation** — enregistrer des clients, les rattacher optionnellement aux ventes, consulter leur historique — sur laquelle s'appuieront les briques ultérieures (crédit/ardoise, tarifs par client, abonnements). Il ne contient **aucune** logique de crédit : c'est délibérément un carnet d'adresses relié aux ventes.

## Décisions de cadrage (2026-07-25)

- **Rattachement à la caisse : optionnel et discret.** La vente reste anonyme par défaut (flux actuel strictement inchangé). Un champ « Client (optionnel) » permet de rechercher/sélectionner un client, et de le créer à la volée sans quitter la caisse.
- **Fiche client : nom + téléphone unique + note.** `name` obligatoire ; `phone` obligatoire et **unique** (identifiant naturel, empêche les doublons) ; `note` libre optionnelle.
- **Accès à la page de gestion : propriétaire + gestionnaire.** Le caissier ne gère pas la liste, mais peut **créer** un client à la volée et le sélectionner à la caisse.
- **Historique : fiche client détaillée.** Depuis la liste, cliquer un client ouvre sa fiche (coordonnées + ses ventes passées + total dépensé). C'est là que s'affichera plus tard le solde de crédit.

## Contraintes non négociables

- **Le flux de vente anonyme existant reste strictement inchangé.** Champ client vide = comportement d'aujourd'hui. L'ajout de `customer_id` à `POST /sales` et à `SaleController::store()` est **strictement additif** (même patron que le champ `uuid` de B6 : une règle de validation, un champ de plus à la création, aucune ligne existante modifiée).
- **Hors périmètre v1 :** aucune logique de crédit/solde/ardoise ; le mode hors-ligne (B6) n'est pas concerné — une vente encaissée hors-ligne reste anonyme (pas de `customer_id` dans la file de synchro), pour ne pas complexifier le moteur de synchro.
- Réponses `{success, message, data}`. Montants entiers FCFA. Rôles guard `web` : `proprietaire`, `gestionnaire`, `caissier`, `vendeur`.
- Réutiliser les patrons existants : `UserController` (CRUD + suppression bloquée si transactions) et `users.html` (page de gestion) comme modèles.

## Modèle de données

Nouvelle table **`customers`** :
```
id            (PK)
name          string, obligatoire
phone         string, obligatoire, UNIQUE
note          text, nullable
timestamps
```

La table **`sales`** gagne **`customer_id`** : `foreignId nullable`, contrainte vers `customers` (`nullOnDelete` non requis car la suppression d'un client ayant des ventes est bloquée applicativement). Nullable → zéro impact sur les ventes existantes (`customer_id = null`).

Modèle `Customer` : relation `sales() : hasMany(Sale)` ; méthode `hasSales() : bool` (`return $this->sales()->exists();`) sur le modèle du `Product::hasSales()` existant. Modèle `Sale` : ajout de `'customer_id'` au `$fillable` + relation `customer() : belongsTo(Customer)`.

## Backend — `CustomerController` + route sur `/sales`

Nouveau `CustomerController`, calqué sur `UserController` :

- **`GET /customers`** (`proprietaire|gestionnaire`) — liste avec recherche optionnelle `?search=` (nom ou téléphone). Renvoie chaque client formaté + son nombre de ventes.
- **`POST /customers`** (`proprietaire|gestionnaire|caissier` — la seule action ouverte au caissier, pour la création à la volée) — validation `name: required|string|max:150`, `phone: required|string|unique:customers,phone`, `note: nullable|string`. Message clair si le téléphone est déjà pris (Laravel `unique` → 422). `activity_log('creation_client', ...)`.
- **`GET /customers/{customer}`** (`proprietaire|gestionnaire`) — fiche détaillée : coordonnées, liste des ventes du client (id, `receipt_number`, date, total) triées récentes d'abord, et `total_depense` (somme des totaux). C'est ici que le futur solde de crédit s'ajoutera.
- **`PUT /customers/{customer}`** (`proprietaire|gestionnaire`) — modification. `phone` unique en ignorant le client courant.
- **`DELETE /customers/{customer}`** (`proprietaire|gestionnaire`) — suppression **bloquée (422)** si `$customer->hasSales()` (« Impossible : ce client a des ventes. »), sinon supprime. Même patron que `UserController::destroy` / `ProductController::destroy`.

Sur **`POST /sales`** (`SaleController::store`, additif) : la validation gagne `'customer_id' => 'nullable|exists:customers,id'` ; le `Sale::create([...])` gagne `'customer_id' => $request->customer_id`. `formatSale()` gagne une clé `'customer' => $sale->customer?->name` (comme `'cashier'`/`'vendor'` existants). Aucune autre ligne de `store()` touchée.

Routes : nouveau groupe `Route::prefix('customers')` dans `auth:sanctum`, avec le gate `role:proprietaire|gestionnaire` sur toutes les routes SAUF `POST /` qui porte `role:proprietaire|gestionnaire|caissier`.

## Frontend

**Page `clients.html`** — nouveau lien « Clients » dans la section Gestion de la barre latérale (`data-role="proprietaire,gestionnaire"`), ajouté à l'identique sur les pages ayant une barre latérale, cohérent avec les liens existants. Calquée sur `users.html` :
- Tableau des clients (nom, téléphone, nombre de ventes) avec champ de recherche (débounce, filtre nom/téléphone via `?search=`).
- Bouton « Nouveau client » → modal de création/modification (nom, téléphone, note).
- Clic sur une ligne → **fiche détaillée** (modal) : coordonnées + tableau des ventes passées (date, n° reçu, total) + total dépensé. Boutons Modifier / Supprimer (suppression désactivée ou message si le client a des ventes).

**Intégration caisse (`pos.html`)** — un champ discret « Client (optionnel) » près du panier :
- Recherche par nom/téléphone (appelle `GET /customers?search=`), sélection en un clic.
- Un « + » ouvre une mini-saisie (nom + téléphone) → `POST /customers` → le client créé est sélectionné automatiquement.
- Le client sélectionné s'affiche sur le panier avec une croix pour le retirer.
- À l'encaissement, `processSale()` ajoute `customer_id` au corps **seulement si un client est sélectionné** (sinon champ absent = vente anonyme, flux inchangé). Le reçu à l'écran peut afficher le nom du client s'il y en a un.
- **Le chemin hors-ligne de `processSale()` (B6) reste inchangé** : pas de `customer_id` sur une vente hors-ligne en v1.

## Tests

**Backend (PHPUnit) :**
- Création d'un client (201) ; téléphone en doublon refusé (422) ; suppression bloquée si le client a des ventes (422) ; suppression autorisée d'un client sans vente.
- Portées de rôle : un caissier PEUT `POST /customers` mais NE peut PAS `GET /customers` ni `DELETE` (403) ; un gestionnaire peut tout.
- Vente avec `customer_id` : la vente est bien rattachée, `formatSale` renvoie le nom du client.
- **Non-régression** : une vente sans `customer_id` fonctionne exactement comme avant (`customer_id` null).
- Fiche détaillée : `GET /customers/{id}` renvoie l'historique des ventes et le total dépensé corrects.

**Frontend (vérification navigateur) :** créer un client depuis la page de gestion et à la volée depuis la caisse ; rattacher un client à une vente et vérifier qu'il apparaît sur la fiche avec le bon historique ; confirmer qu'une vente anonyme reste possible et inchangée.

## Découpage en unités

- **Backend** — migration `customers` + `sales.customer_id` ; modèle `Customer` + ajouts au modèle `Sale` ; `CustomerController` (CRUD + fiche) ; ajout additif à `SaleController::store` + `formatSale` ; routes ; tests.
- **Frontend** — page `clients.html` (gestion + fiche) + lien de nav ; intégration du sélecteur client dans `pos.html`.

## Critères de succès

- On peut créer, retrouver, modifier un client ; le téléphone unique empêche les doublons.
- Une vente peut être rattachée à un client (à la caisse) ou rester anonyme (défaut, inchangé).
- La fiche client affiche l'historique d'achat et le total dépensé.
- Zéro régression sur la vente anonyme et sur les 91 tests existants.
- La fondation est prête à recevoir le futur volet crédit (la fiche client est le point d'ancrage du solde).
