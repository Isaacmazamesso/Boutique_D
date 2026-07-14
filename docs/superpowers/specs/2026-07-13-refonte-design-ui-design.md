# Refonte design UI — Boutique D

**Date :** 2026-07-13
**Statut :** Validé par le client — spec approuvé, y compris périmètre (Login inclus) (2026-07-13)

## Contexte

Le design actuel (indigo/violet/amber, Bootstrap Icons, look "SaaS admin générique") a été rejeté par le client à deux reprises. Le client demande une refonte complète vers un style "SaaS premium" inspiré de Linear / Stripe / Vercel : fond très majoritairement blanc, typographie forte, hiérarchie visuelle marquée, palette limitée.

Deux maquettes du Dashboard ont été validées via le compagnon visuel de brainstorming (`.superpowers/brainstorm/152143-1783969913/content/dashboard-mockup-v2.html`) avant d'écrire ce spec.

## Contraintes non négociables

- **Aucune modification de logique métier, d'API, de route, ou de structure de données.** Seuls le HTML/CSS/JS de présentation changent.
- Toutes les fonctionnalités et flux utilisateurs existants doivent être préservés à l'identique.
- Le module Inventaire livré au Sprint 1 (`inventory-count.html`) suit les mêmes règles : redesign visuel seulement, aucun changement de logique.

## Design tokens

```css
--bg:            #F8FAFC;  /* fond de page */
--surface:       #FFFFFF;  /* cartes, sidebar, topbar */
--text:          #0F172A;  /* texte principal */
--text-muted:    #64748B;  /* texte secondaire */
--text-subtle:   #94A3B8;  /* labels, texte tertiaire */
--border:        #E2E8F0;  /* bordures de cartes/inputs */
--border-soft:   #F1F5F9;  /* séparateurs internes discrets */
--accent:        #2563EB;  /* bleu — actions principales, liens actifs */
--accent-dark:   #1D4ED8;  /* hover accent */
--accent-soft:   #EFF6FF;  /* fonds teintés accent (nav actif, kpi featured) */
--success:       #16A34A;  --success-soft: #F0FDF4;
--warning:       #F59E0B;  --warning-soft: #FFFBEB;
--error:         #DC2626;  --error-soft:   #FEF2F2;

--radius:        12px;   /* inputs, boutons, badges */
--radius-lg:     16px;   /* cartes */
--shadow-xs:     0 1px 2px rgba(15,23,42,.04);
--shadow-sm:     0 1px 3px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.04);
--shadow-md:     0 4px 12px rgba(15,23,42,.07);

--font:          'Inter', system-ui, sans-serif;  /* inchangé */
```

Police : Inter (déjà utilisée, conservée — cohérente avec la demande "typographie moderne et très lisible").
Icônes : **Lucide** (`lucide@0.462.0`, épinglé) remplace Bootstrap Icons partout — trait fin (1.75), taille 15-18px, cohérent avec l'esthétique Linear/Stripe.

## Composants du design system

Spécifiés et validés dans la maquette V2 (`dashboard-mockup-v2.html`), à extraire en classes CSS réutilisables dans `css/app.css` :

- **Sidebar** : fond blanc, item actif = fond `--accent-soft` + barre verticale 3px `--accent` à gauche + icône sur fond blanc élevé (`shadow-xs`) ; badge de notification rouge arrondi ; pied de sidebar (avatar + nom + rôle) dans une carte discrète cliquable.
- **Topbar / en-tête de page** : titre + sous-titre à gauche, actions à droite (bouton primaire accentué + boutons secondaires discrets `.btn`).
- **Cartes KPI** : carte "featured" (1ère métrique clé, fond dégradé `accent-soft`→blanc, valeur 34px, sparkline SVG) + cartes standards (valeur 23px, label discret, icône dans pastille colorée).
- **Cartes de contenu** (`.card`) : bordure fine, radius 16px, `shadow-xs`, en-tête avec titre + action optionnelle à droite, séparateur `border-soft`.
- **Tableaux** : en-têtes majuscules, discrets, letter-spacing ; lignes avec padding généreux (13px vertical), hover `border-soft`, action (chevron) qui apparaît au survol.
- **Formulaires** : label 12.5px semi-bold au-dessus du champ, input/select avec bordure fine et anneau de focus `accent-soft` (3px), état d'erreur (bordure + anneau rouge + message avec icône).
- **Boutons** : `.btn` (secondaire, bordure fine, fond blanc), `.btn-primary` (fond accent, hover plus foncé + micro-élévation), `.btn-ghost` (texte seul, pour actions tertiaires).
- **Badges / pills** : fond teinté clair + texte de la couleur foncée correspondante, radius complet (999px), utilisés pour statuts (en ligne/hors ligne, stock, etc.).
- **Alertes / toasts** : même traitement que les badges mais en ligne pleine largeur, icône + texte + chevron optionnel.
- **États vides** : icône dans cercle discret + titre + sous-texte + CTA primaire — remplace les états vides actuels plus austères.
- **Animations** : transitions 150–250ms sur hover/focus/ouverture (`transition: all .15s` / `.2s`), pas d'animation sur le contenu au chargement.

## Responsive

- **< 1140px** : grille KPI 4→2 colonnes, grilles 3 colonnes → 1 colonne.
- **< 900px** : sidebar se réduit à un rail d'icônes (68px, labels masqués), grilles 2 colonnes → 1 colonne, padding du contenu réduit.
- **< 560px** : grille KPI → 1 colonne, en-tête de page passe en colonne (titre puis actions).

Ces points de rupture doivent être appliqués de façon identique sur les 8 pages (pas de comportement responsive divergent d'une page à l'autre).

## Périmètre — pages concernées et décisions

| # | Page | Fichier | Décision |
|---|------|---------|----------|
| 0 | Login | `login.html` | Incluse au périmètre — confirmé par le client (2026-07-13). |
| 1 | Dashboard | `dashboard.html` | Design déjà validé (maquette V2) — à transposer en code réel avec les données live. |
| 2 | Produits (+ Catégories) | `products.html` | Catégories **reste un onglet** dans cette page (pas de fichier séparé) — décision explicite du client. |
| 3 | Stock & Inventaire | `stock.html`, `inventory-count.html` | Les deux fichiers doivent utiliser le même système (la page inventaire livrée au Sprint 1 doit être ré-alignée visuellement). |
| 4 | Point de vente (POS) | `pos.html` | — |
| 5 | Rapports | `reports.html` | — |
| 6 | Utilisateurs | `users.html` | — |
| 7 | Profil | `profile.html` (nouveau) | Nouvelle page : infos du compte (nom, username, rôle), formulaire de changement de mot de passe branché sur `PUT /auth/password` (endpoint existant, jamais utilisé par une UI). Aucune nouvelle route backend. |

**Hors périmètre (explicitement exclu par le client) :** Paramètres système (seuils de remise, écart de caisse, etc.) — aucune route API n'existe pour cette fonctionnalité ; sa création serait de la logique métier, hors scope d'une refonte purement visuelle. Reporté à un futur sprint fonctionnel.

## Méthode de déploiement

Le client a demandé un déroulé **page par page**, avec validation à la fin de chaque page avant de passer à la suivante — cohérent avec la méthode déjà utilisée au Sprint 1 (un correctif à la fois, testé et validé).

Pour chaque page :
1. Appliquer le design system (tokens + composants) au HTML/CSS existant.
2. Ne toucher à aucun appel API, aucun id/`data-*` dont dépend le JS existant, sauf si le changement de markup l'exige — dans ce cas, adapter le JS en conséquence sans changer son comportement fonctionnel.
3. Vérifier qu'aucune fonctionnalité n'est cassée (test manuel ou via API, comme pour l'inventaire au Sprint 1).
4. Fournir : fichiers modifiés, ce qui a changé visuellement, tests manuels de non-régression.

Une fois les 8 pages terminées : passe finale d'harmonisation (cohérence des marges, ombres, hauteurs de composants et états sur l'ensemble de l'application).

## Risques identifiés

- `js/app.js` et `js/api.js` contiennent des sélecteurs (`#topbar-user`, `.nav-item[data-role]`, `.kpi-card`, etc.) utilisés par toutes les pages — un changement de structure DOM doit rester compatible avec ces sélecteurs, ou `app.js` doit être mis à jour une seule fois (pas page par page) pour éviter les incohérences entre pages déjà migrées et pages en attente.
- Le remplacement Bootstrap Icons → Lucide touche les 8 pages + potentiellement `js/app.js` (aucune icône n'y est actuellement générée dynamiquement — à vérifier page par page).
- `manifest.json`/`sw.js` référencent des icônes d'app manquantes (`frontend/icons/` vide) — hors scope de cette refonte CSS, déjà noté comme gap Sprint 1 dans [[cahier-des-charges-conformite]].
