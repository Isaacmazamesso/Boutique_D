# Refonte globale — Boutique D (design + conformité fonctionnelle)

**Date :** 2026-07-24
**Statut :** Validé par le client (2026-07-24)

## Contexte

Le client demande une application « hors du commun tant sur le frontend que sur le backend » et « bien fonctionnelle de tous les côtés ». Deux chantiers existent :

1. **Refonte design** — spec validée le 2026-07-13 (`2026-07-13-refonte-design-ui-design.md`) : style « SaaS premium » type Linear/Stripe, fond blanc dominant, palette slate/bleu, icônes Lucide. Déjà commencée sur la branche `design/refonte-ui` : design system (`css/app.css`), helper `refreshIcons()` (`js/app.js`) et page Login livrés.
2. **Conformité fonctionnelle** — audit du cahier des charges v1.0 (2026-07-13) : plusieurs modules absents ou non câblés côté UI.

**Décisions de cadrage (2026-07-24) :**
- Séquencement : **design d'abord**, puis conformité fonctionnelle.
- Direction visuelle : **garder la base validée et l'enrichir** de touches signature (pas de nouveau brainstorming visuel).
- Périmètre fonctionnel : **conformité totale** au cahier des charges, tests automatisés inclus.

## Phase A — Refonte design (branche `design/refonte-ui`)

La spec du 2026-07-13 reste la référence pour les tokens, composants, responsive et le périmètre des pages. Cette phase la complète avec :

### A0. Enrichissements du design system

Ajoutés une seule fois dans `css/app.css` + `js/app.js`, avant de continuer les pages :

- Micro-animations sur hover/clic (150–250 ms), sans animation de contenu au chargement initial.
- Transitions d'apparition/disparition des modals et toasts (fondu + translation légère).
- Sparklines SVG sur les cartes KPI (données déjà disponibles via l'API dashboard).
- États vides illustrés (icône en cercle + titre + sous-texte + CTA).
- Skeleton loaders pendant les chargements API (remplace les zones vides brutes).
- Chiffres tabulaires (`font-variant-numeric: tabular-nums`) pour tous les montants et tableaux.

Contrainte inchangée : **aucune modification de logique métier, d'API ou de route** en Phase A.

### A1 → A8. Pages restantes, une par une

Ordre : Dashboard → Produits → Stock → Inventaire (`inventory-count.html`) → POS → Rapports → Utilisateurs → **Profil** (nouvelle page : infos compte + changement de mot de passe via `PUT /auth/password`, endpoint existant).

Chaque page : appliquée, vérifiée sans régression fonctionnelle, commitée, **validée par le client avant la suivante** (méthode établie au Sprint 1).

### A9. Harmonisation finale

Cohérence marges/ombres/hauteurs/états sur les 9 pages + vérification responsive aux points de rupture 1140 / 900 / 560 px, identiques partout.

**Fin de phase :** merge de `design/refonte-ui` dans `master`.

## Phase B — Conformité fonctionnelle totale (après merge Phase A)

Un module à la fois, sur une branche dédiée par module, chacun livré **avec ses tests automatisés (Pest/PHPUnit)**, testé et validé avant le suivant. Aucun changement visuel hors composants nécessaires au module (le design system de la Phase A est réutilisé tel quel).

Ordre — du plus utile au quotidien vers le plus structurel :

| # | Module | Contenu |
|---|--------|---------|
| B1 | Reçus imprimables | Génération PDF (dompdf, déjà installé) avec nom du caissier ; feuille de style d'impression 80 mm pour ticket thermique via l'impression navigateur. |
| B1b | Remboursement POS (UI) | Câbler le flux de remboursement sur l'endpoint existant `POST /sales/{id}/refund` (jamais exploité par l'UI — constaté lors de la Phase A). |
| B2 | Exports rapports | Câblage PDF (dompdf) et Excel (fast-excel) sur les rapports existants. |
| B3 | Prix en masse (Module 3.3) | Endpoint de modification en masse + UI dans Produits, avec traçabilité dans l'historique de prix existant. |
| B4 | Paramètres système | Table `settings` + API + page Paramètres (seuils de remise, écart de caisse, etc.), accès admin. |
| B5 | Vente tablette-vendeur (Module 4.3) | Panier créé par un vendeur, statut « en attente », repris/validé/encaissé à la caisse. |
| B6 | Offline/sync (Module 8) | IndexedDB, bandeau de connectivité, file d'attente des ventes hors ligne, synchronisation au retour du réseau, notifications. **Conception dédiée requise avant implémentation** (stratégie de résolution de conflits stock/ventes). |
| B7 | Socle de tests final | Compléter la couverture des modules critiques existants (auth, POS, stock/inventaire). |

Hors scope (cahier des charges lui-même) : Module 6 (Clients & Crédit), reporté à une v2.

## Méthode et risques

- Chaque page (Phase A) / module (Phase B) = livraison autonome : cause/contenu expliqué, fichiers modifiés, commandes à exécuter, tests manuels de non-régression — format validé par le client.
- Phase A : les sélecteurs consommés par `js/app.js` et le JS inline des pages (`.card`, `.kpi-card`, `#topbar-user`, etc.) sont préservés ; tout changement de markup s'accompagne de l'adaptation du JS sans changement de comportement.
- Phase B6 (offline) touche toutes les pages : gardé pour la fin, avec sa propre mini-spec (conflits, idempotence des ventes synchronisées, plafond de file d'attente).
- Icônes : Lucide 0.462.0 épinglé avec hash SRI (voir plan du 2026-07-13) — ne jamais monter de version sans recalculer le hash.

## Critères de succès

- Phase A : les 9 pages utilisent le même design system enrichi, zéro régression fonctionnelle, responsive identique partout.
- Phase B : chaque exigence du cahier des charges v1.0 (hors Module 6) est implémentée, couverte par au moins un test automatisé, et validée manuellement par le client.
