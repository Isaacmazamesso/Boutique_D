# Phase B7 — Socle de tests des modules critiques — Design

**Date :** 2026-07-25
**Statut :** Validé par le client (2026-07-25)

## Contexte

Dernier module de la conformité fonctionnelle (Phase B). Les modules construits au tout début du projet — authentification, cœur du POS (`SaleController::store`), mouvements de stock, workflow d'inventaire, sessions de caisse — n'ont **aucun test automatisé dédié**. Les modules ajoutés pendant la refonte (B1–B6) sont couverts (47 → 51 tests actuels), mais ces fondations historiques ne le sont pas. B7 construit ce filet de sécurité pour figer leur comportement actuel avant de considérer la v1 comme stable.

## Décision de cadrage (2026-07-25)

**Couverture complète** : un fichier de test par module critique, couvrant les chemins nominaux ET les cas limites / sécurité. Pas de rapport de couverture de code (pas d'installation de pilote Xdebug/PCOV) — l'objectif est de verrouiller les comportements métier connus, pas de mesurer un pourcentage.

## Principe directeur

Ces tests **caractérisent le comportement existant** (characterization tests) : ils lisent le code actuel et affirment ce qu'il fait aujourd'hui, sans le modifier. Aucun test ne doit entraîner un changement du code de production. Si un test révèle un comportement qui semble être un bug, il est **documenté dans le rapport de la tâche** et remonté — mais le code n'est pas corrigé dans ce module (la correction serait un module fonctionnel distinct). B7 ne touche qu'aux fichiers de test et, si nécessaire, au trait de fixtures `tests/Support/CreatesShopData.php` (ajouts additifs uniquement).

## Périmètre — 5 fichiers de test

### 1. `tests/Feature/AuthTest.php` (~10 tests)
`POST /auth/login` : succès (200 + token + user formaté) ; mauvais mot de passe (401, incrémente `failed_attempts`) ; verrouillage après le 5e échec (429 + `locked_until` posé) ; compte désactivé `is_active=false` (403) ; compte verrouillé (`locked_until` futur → 429) ; utilisateur inexistant (401) ; réinitialisation de `failed_attempts` à 0 après un login réussi. `PUT /auth/password` (authentifié) : succès (mot de passe effectivement changé, reconnexion possible avec le nouveau) ; mauvais mot de passe actuel (422) ; nouveau mot de passe < 6 caractères (422) ; absence de confirmation (`new_password_confirmation`) (422).

### 2. `tests/Feature/SaleStoreTest.php` (~8 tests)
`POST /sales` (le cœur money-critical, aujourd'hui seulement effleuré par `SaleFlowTest`) : refus stock insuffisant (422, stock inchangé) ; quantité en vente gros inférieure au `wholesale_min_qty` (422) ; prix gros correctement appliqué quand `sale_type=gros` et quantité suffisante ; remise au-delà de `remise_max_sans_auth` refusée pour un caissier (403) mais **autorisée** pour le propriétaire ; montant espèces insuffisant (`amount_paid < total`) (422) ; caissier sans session de caisse ouverte (422) ; calcul correct du `change_given` (monnaie rendue) sur une vente espèces avec surpaiement.

### 3. `tests/Feature/StockMovementTest.php` (~7 tests)
`POST /stock/entries` : incrémente le stock du produit et renvoie le nouveau stock (201) ; met à jour le `purchase_price` du produit si différent. `POST /stock/exits` : décrémente le stock ; refus si quantité > stock disponible (422, stock inchangé) ; refus si quantité > `sortie_stock_max` pour un caissier (403) mais autorisé pour le propriétaire ; motif invalide rejeté (422 — `reason` hors enum). `GET /stock/alerts` : liste les produits en stock bas (≤ `min_stock_alert`) et en rupture.

### 4. `tests/Feature/InventoryWorkflowTest.php` (~7 tests)
Workflow complet de `InventoryController` : création d'un inventaire complet (201, snapshot du `theoretical_qty` = stock actuel de chaque produit actif) ; un seul inventaire `en_cours` à la fois (second `POST /inventories` → 422) ; comptage (`POST /inventories/{id}/count` calcule `difference = counted_qty - theoretical_qty`) ; validation (`POST /inventories/{id}/validate` ajuste le stock de chaque produit à sa `counted_qty` et passe l'inventaire à `valide`) ; refus de valider si des produits ne sont pas comptés (422) ; refus de compter un inventaire déjà `valide` (422) ; inventaire `partiel` limité à une catégorie (ne crée des lignes que pour les produits de cette catégorie).

### 5. `tests/Feature/CashSessionTest.php` (~6 tests)
`POST /cash-sessions/open` : ouverture (201) ; refus si une session est déjà ouverte pour ce caissier (422). `POST /cash-sessions/close` : clôture avec calcul de l'écart (`theoretical = opening_amount + ventes espèces − remboursements` ; `difference = closing_amount − theoretical`) ; `alerte_ecart=true` si `|difference| > ecart_caisse_alerte` ; refus de clôturer sans session ouverte (404). `GET /cash-sessions/current` : renvoie la session ouverte, ou une réponse gérée quand il n'y en a pas.

## Extensions du trait de fixtures (si nécessaires, additives)

`CreatesShopData` fournit déjà `makeUser`, `makeProduct`, `openSession`, `makeSaleViaApi`. Si un test en a besoin, ajouter (sans modifier l'existant) : un helper pour créer un inventaire via l'API, et/ou un helper pour créer une catégorie nommée. Ces ajouts restent dans le trait, réutilisables.

## Ce qui n'est PAS dans le périmètre

- Aucun test frontend (pas de framework JS dans le projet ; les vérifications frontend restent manuelles en navigateur, comme pour B1–B6).
- Pas de rapport de couverture de code (pas d'outillage Xdebug/PCOV).
- Aucune modification du code de production. Un comportement suspect découvert est signalé, pas corrigé ici.
- Modules déjà couverts (B1–B6) non re-testés.

## Critères de succès

- Les 5 fichiers ajoutés, tous verts, sortie de test propre (aucun warning).
- La suite complète passe (51 tests existants + ~38 nouveaux ≈ 89 tests).
- Chaque comportement métier listé ci-dessus est affirmé par au moins un test qui échouerait si ce comportement régressait.
- Tout comportement suspect rencontré est documenté dans le rapport de tâche pour décision ultérieure du client.
