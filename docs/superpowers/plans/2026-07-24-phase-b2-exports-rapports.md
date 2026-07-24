# Phase B2 — Exports PDF/Excel des rapports — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter l'export PDF (dompdf) et Excel (fast-excel) aux 4 rapports existants (ventes, stock, trésorerie, employés), sans changer le comportement JSON existant, avec tests automatisés.

**Architecture:** Chaque méthode de `ReportController` (`sales`, `stock`, `treasury`, `employees`) est refactorée pour extraire son calcul dans une méthode privée `computeXxxReport(Request $request): array` réutilisée par 3 actions : l'action JSON existante (inchangée en sortie), une nouvelle action `xxxPdf` (vue Blade A4, dompdf — déjà utilisé en Phase B1) et une nouvelle action `xxxExcel` (fast-excel — jamais utilisé, installé). Le frontend ajoute un helper générique `downloadFile(url, filename)` dans `app.js` (télécharge un blob authentifié) et deux boutons PDF/Excel par onglet de `reports.html`.

**Tech Stack:** Laravel 12, barryvdh/laravel-dompdf ^3.1 (déjà utilisé en B1), rap2hpoutre/fast-excel ^5.9 (nouveau dans ce module), PHPUnit (`php artisan test`), frontend vanilla JS existant.

## Global Constraints

- Phase B, spec `docs/superpowers/specs/2026-07-24-refonte-globale-design.md`, module B2 : « Câblage PDF (dompdf) et Excel (fast-excel) sur les rapports existants. »
- **Aucun changement de comportement des 4 endpoints JSON existants** (`GET /reports/sales|stock|treasury|employees`) — même forme de réponse, mêmes règles de validation, même contenu. Le refactor extrait le calcul, il ne le modifie pas.
- Toutes les nouvelles routes restent dans le groupe `role:proprietaire` existant (`backend/routes/api.php`, groupe `reports`) — aucun changement d'autorisation.
- Branche de travail : `feat/b2-exports-rapports`, créée depuis `master`. Un commit par tâche. Ne pas pousser vers origin sans demande du client.
- Tests : `cd backend && php artisan test` doit passer en entier (17 tests existants + les nouveaux) à la fin de chaque tâche.
- Réutiliser `tests/Support/CreatesShopData.php` (Phase B1) pour les fixtures — ne pas dupliquer sa logique.
- Montants : entiers FCFA, convention existante. Dates : format `d/m/Y` (déjà la convention des rapports).
- Ne PAS toucher : la logique de calcul elle-même (montants, filtres, seuils), `frontend/js/api.js`, tout ce qui n'est pas listé dans File Structure.

## File Structure

- **Modify:** `backend/app/Http/Controllers/Api/ReportController.php` — extraction des 4 `computeXxxReport()` + 8 nouvelles actions (`salesPdf`, `salesExcel`, `stockPdf`, `stockExcel`, `treasuryPdf`, `treasuryExcel`, `employeesPdf`, `employeesExcel`).
- **Modify:** `backend/routes/api.php` — 8 nouvelles routes dans le groupe `reports` existant.
- **Create:** `backend/resources/views/reports/sales.blade.php`, `stock.blade.php`, `treasury.blade.php`, `employees.blade.php` — vues PDF A4.
- **Create:** `backend/tests/Feature/ReportExportTest.php` — un seul fichier de test couvrant les 4 rapports × (JSON inchangé + PDF + Excel), pour éviter la duplication de setup entre 4 fichiers.
- **Modify:** `frontend/js/app.js` — nouveau helper `downloadFile(url, filename)`.
- **Modify:** `frontend/reports.html` — boutons PDF/Excel dans les 4 onglets.

---

### Task 1: Rapport Ventes — refactor + PDF + Excel (TDD)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/ReportController.php` (méthode `sales()`, lignes 23-91)
- Modify: `backend/routes/api.php` (groupe `reports`)
- Create: `backend/resources/views/reports/sales.blade.php`
- Test: `backend/tests/Feature/ReportExportTest.php` (créé ici, complété par les tâches suivantes)

**Interfaces:**
- Consumes: `tests/Support/CreatesShopData.php` (`makeUser`, `makeProduct`, `makeSaleViaApi`).
- Produces: `ReportController::computeSalesReport(Request $request): array` — retourne exactement le tableau actuellement renvoyé par `sales()` (mêmes clés : `periode`, `resume`, `par_type`, `par_paiement`, `ventes`). Consommé par `sales()`, `salesPdf()`, `salesExcel()`.

- [ ] **Step 1: Écrire le test qui échoue**

Créer `backend/tests/Feature/ReportExportTest.php` :
```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    // ── Ventes ───────────────────────────────────────────────────────────

    public function test_le_rapport_ventes_json_est_inchange_apres_le_refactor(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500);
        $this->makeSaleViaApi($owner, $product, qty: 2);

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/reports/sales?period=today');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(1000, $data['resume']['total_ca']);
        $this->assertSame(1, $data['resume']['nb_ventes']);
        $this->assertCount(1, $data['ventes']);
        $this->assertArrayHasKey('periode', $data);
        $this->assertArrayHasKey('par_type', $data);
        $this->assertArrayHasKey('par_paiement', $data);
    }

    public function test_le_pdf_du_rapport_ventes_est_genere(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500);
        $this->makeSaleViaApi($owner, $product, qty: 2);

        Sanctum::actingAs($owner);
        $response = $this->get('/api/reports/sales/pdf?period=today');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_le_pdf_du_rapport_ventes_exige_le_role_proprietaire(): void
    {
        $cashier = $this->makeUser('caissier');

        Sanctum::actingAs($cashier);
        $this->getJson('/api/reports/sales/pdf?period=today')->assertForbidden();
    }

    public function test_l_excel_du_rapport_ventes_est_genere(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500);
        $this->makeSaleViaApi($owner, $product, qty: 2);

        Sanctum::actingAs($owner);
        $response = $this->get('/api/reports/sales/excel?period=today');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
    }
}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `cd backend && php artisan test --filter=ReportExportTest`
Attendu : le 1er test (JSON inchangé) PASSE déjà (aucun refactor encore fait) ; les 3 suivants FAIL (404 — routes inexistantes).

- [ ] **Step 3: Refactorer `sales()` en `computeSalesReport()`**

Dans `ReportController.php`, remplacer la méthode `sales()` (lignes 23-91) par :
```php
    public function sales(Request $request): JsonResponse
    {
        return $this->success($this->computeSalesReport($request));
    }

    private function computeSalesReport(Request $request): array
    {
        $request->validate([
            'period'         => 'nullable|in:today,week,month,custom',
            'start_date'     => 'required_if:period,custom|nullable|date',
            'end_date'       => 'required_if:period,custom|nullable|date|after_or_equal:start_date',
            'cashier_id'     => 'nullable|exists:users,id',
            'sale_type'      => 'nullable|in:detail,gros',
            'payment_method' => 'nullable|in:especes,mobile_money',
        ]);

        [$start, $end] = $this->resolvePeriod($request);

        $query = Sale::with(['cashier:id,name', 'items.product:id,name'])
            ->whereBetween('created_at', [$start, $end])
            ->when($request->cashier_id, fn($q) => $q->where('cashier_id', $request->cashier_id))
            ->when($request->sale_type, fn($q) => $q->where('sale_type', $request->sale_type))
            ->when($request->payment_method, fn($q) => $q->where('payment_method', $request->payment_method))
            ->latest();

        $sales = $query->get();

        $totalCA      = $sales->sum('total');
        $totalRemises = $sales->sum(fn($s) =>
            $s->discount_type === 'percent'
                ? (int) round($s->subtotal * $s->discount_value / 100)
                : $s->discount_value
        );

        $byType = [
            'detail' => ['count' => 0, 'total' => 0],
            'gros'   => ['count' => 0, 'total' => 0],
        ];
        $byPayment = [
            'especes'      => ['count' => 0, 'total' => 0],
            'mobile_money' => ['count' => 0, 'total' => 0],
        ];

        foreach ($sales as $s) {
            $byType[$s->sale_type]['count']++;
            $byType[$s->sale_type]['total'] += $s->total;
            $byPayment[$s->payment_method]['count']++;
            $byPayment[$s->payment_method]['total'] += $s->total;
        }

        return [
            'periode'      => ['debut' => $start->format('d/m/Y'), 'fin' => $end->format('d/m/Y')],
            'resume' => [
                'nb_ventes'    => $sales->count(),
                'total_ca'     => $totalCA,
                'panier_moyen' => $sales->count() > 0 ? (int) round($totalCA / $sales->count()) : 0,
                'total_remises'=> $totalRemises,
            ],
            'par_type'     => $byType,
            'par_paiement' => $byPayment,
            'ventes'       => $sales->map(fn($s) => [
                'id'             => $s->id,
                'receipt_number' => $s->receipt_number,
                'date'           => $s->created_at->format('d/m/Y H:i'),
                'cashier'        => $s->cashier?->name,
                'sale_type'      => $s->sale_type,
                'payment_method' => $s->payment_method,
                'total'          => $s->total,
                'nb_articles'    => $s->items->sum('quantity'),
            ])->toArray(),
        ];
    }
```
(Seul changement fonctionnel : `->toArray()` final sur `ventes` pour que la clé soit un tableau indexé propre à réutiliser par le PDF/Excel — la sortie JSON est strictement identique, Laravel sérialise les deux formes de la même façon.)

- [ ] **Step 4: Ajouter les routes**

Dans `backend/routes/api.php`, dans le groupe `Route::prefix('reports')->group(...)`, remplacer :
```php
            Route::get('sales', [ReportController::class, 'sales']);
```
par :
```php
            Route::get('sales', [ReportController::class, 'sales']);
            Route::get('sales/pdf', [ReportController::class, 'salesPdf']);
            Route::get('sales/excel', [ReportController::class, 'salesExcel']);
```

- [ ] **Step 5: Ajouter les imports et les 2 actions**

En haut de `ReportController.php`, ajouter :
```php
use Barryvdh\DomPDF\Facade\Pdf;
use Rap2hpoutre\FastExcel\FastExcel;
```

Après `sales()`, ajouter :
```php
    public function salesPdf(Request $request)
    {
        $data = $this->computeSalesReport($request);

        return Pdf::loadView('reports.sales', $data)
            ->stream('rapport-ventes-' . now()->format('Y-m-d') . '.pdf');
    }

    public function salesExcel(Request $request)
    {
        $data = $this->computeSalesReport($request);

        $rows = collect($data['ventes'])->map(fn($v) => [
            'N° Reçu'  => $v['receipt_number'],
            'Date'     => $v['date'],
            'Caissier' => $v['cashier'] ?? '—',
            'Type'     => $v['sale_type'] === 'gros' ? 'Gros' : 'Détail',
            'Paiement' => $v['payment_method'] === 'especes' ? 'Espèces' : 'Mobile Money',
            'Articles' => $v['nb_articles'],
            'Total'    => $v['total'],
        ]);

        return (new FastExcel($rows))->download('rapport-ventes-' . now()->format('Y-m-d') . '.xlsx');
    }
```

- [ ] **Step 6: Créer la vue PDF**

`backend/resources/views/reports/sales.blade.php` :
```blade
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; padding: 24px; }
  h1 { font-size: 18px; margin-bottom: 2px; }
  .sub { color: #555; margin-bottom: 16px; }
  .kpis { display: table; width: 100%; margin-bottom: 16px; }
  .kpi { display: table-cell; width: 25%; padding: 8px 10px; border: 1px solid #ddd; }
  .kpi .label { font-size: 9px; color: #777; text-transform: uppercase; }
  .kpi .value { font-size: 15px; font-weight: bold; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { padding: 5px 6px; border-bottom: 1px solid #eee; text-align: left; font-size: 10px; }
  th { background: #f5f5f5; text-transform: uppercase; font-size: 8px; color: #555; }
  td.r, th.r { text-align: right; }
</style>
</head>
<body>
  <h1>Boutique D — Rapport des ventes</h1>
  <div class="sub">Période du {{ $periode['debut'] }} au {{ $periode['fin'] }}</div>

  <div class="kpis">
    <div class="kpi"><div class="label">CA Total</div><div class="value">{{ number_format($resume['total_ca'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Ventes</div><div class="value">{{ $resume['nb_ventes'] }}</div></div>
    <div class="kpi"><div class="label">Panier moyen</div><div class="value">{{ number_format($resume['panier_moyen'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Remises</div><div class="value">{{ number_format($resume['total_remises'], 0, ',', ' ') }} F</div></div>
  </div>

  <table>
    <thead>
      <tr><th>N° Reçu</th><th>Date</th><th>Caissier</th><th>Type</th><th>Paiement</th><th class="r">Articles</th><th class="r">Total</th></tr>
    </thead>
    <tbody>
      @foreach ($ventes as $v)
      <tr>
        <td>{{ $v['receipt_number'] }}</td>
        <td>{{ $v['date'] }}</td>
        <td>{{ $v['cashier'] ?? '—' }}</td>
        <td>{{ $v['sale_type'] === 'gros' ? 'Gros' : 'Détail' }}</td>
        <td>{{ $v['payment_method'] === 'especes' ? 'Espèces' : 'Mobile Money' }}</td>
        <td class="r">{{ $v['nb_articles'] }}</td>
        <td class="r">{{ number_format($v['total'], 0, ',', ' ') }} F</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
```

- [ ] **Step 7: Vérifier le passage**

Run: `php artisan test --filter=ReportExportTest` — Attendu : 4 PASS.
Run: `php artisan test` — Attendu : tous PASS (21 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/ReportController.php routes/api.php resources/views/reports/sales.blade.php tests/Feature/ReportExportTest.php
git commit -m "feat: export PDF et Excel du rapport ventes"
```

---

### Task 2: Rapport Stock — refactor + PDF + Excel (TDD)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/ReportController.php` (méthode `stock()`, lignes 93-165)
- Modify: `backend/routes/api.php`
- Create: `backend/resources/views/reports/stock.blade.php`
- Test: `backend/tests/Feature/ReportExportTest.php` (ajout)

**Interfaces:**
- Consumes: `computeSalesReport` pattern (Task 1), `CreatesShopData`.
- Produces: `ReportController::computeStockReport(Request $request): array` — même forme que le retour actuel de `stock()` (clés `valeur_stock`, `mouvements`, `alertes`).

- [ ] **Step 1: Ajouter les tests qui échouent**

Ajouter dans `ReportExportTest.php`, après les tests ventes :
```php
    // ── Stock ────────────────────────────────────────────────────────────

    public function test_le_rapport_stock_json_est_inchange_apres_le_refactor(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $this->makeProduct(retail: 500, purchase: 300, stockQty: 20);

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/reports/stock');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayHasKey('valeur_stock', $data);
        $this->assertArrayHasKey('mouvements', $data);
        $this->assertArrayHasKey('alertes', $data);
        $this->assertSame(1, $data['valeur_stock']['nb_produits']);
        $this->assertSame(6000, $data['valeur_stock']['achat']); // 20 x 300
    }

    public function test_le_pdf_du_rapport_stock_est_genere(): void
    {
        $owner = $this->makeUser('proprietaire');
        $this->makeProduct();

        Sanctum::actingAs($owner);
        $response = $this->get('/api/reports/stock/pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_l_excel_du_rapport_stock_a_deux_feuilles(): void
    {
        $owner = $this->makeUser('proprietaire');
        $this->makeProduct();

        Sanctum::actingAs($owner);
        $response = $this->get('/api/reports/stock/excel');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
    }
```

- [ ] **Step 2: Vérifier l'échec**

Run: `php artisan test --filter=ReportExportTest`
Attendu : le test JSON stock PASSE ; PDF et Excel FAIL (404).

- [ ] **Step 3: Refactorer `stock()` en `computeStockReport()`**

Remplacer la méthode `stock()` (lignes 93-165) par :
```php
    public function stock(Request $request): JsonResponse
    {
        return $this->success($this->computeStockReport($request));
    }

    private function computeStockReport(Request $request): array
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $start = $request->start_date ? now()->parse($request->start_date)->startOfDay() : today()->startOfMonth();
        $end   = $request->end_date   ? now()->parse($request->end_date)->endOfDay()     : now();

        $produits = Product::with(['price', 'stock', 'category'])
            ->where('is_active', true)
            ->get();

        $valeurAchat = 0;
        $valeurVente = 0;

        foreach ($produits as $p) {
            $qty = $p->stock?->quantity ?? 0;
            $valeurAchat += $qty * ($p->price?->purchase_price ?? 0);
            $valeurVente += $qty * ($p->price?->retail_price ?? 0);
        }

        $entrees = StockEntry::whereBetween('created_at', [$start, $end])
            ->with('product:id,name')
            ->get();

        $sorties = StockExit::whereBetween('created_at', [$start, $end])
            ->with('product:id,name')
            ->get();

        $rupture  = $produits->filter(fn($p) => $p->stockStatus() === 'rupture')->values();
        $stockBas = $produits->filter(fn($p) => $p->stockStatus() === 'bas')->values();

        return [
            'valeur_stock' => [
                'achat'        => $valeurAchat,
                'vente'        => $valeurVente,
                'marge_brute'  => $valeurVente - $valeurAchat,
                'marge_pct'    => $valeurVente > 0 ? round(($valeurVente - $valeurAchat) / $valeurVente * 100, 1) : 0,
                'nb_produits'  => $produits->count(),
            ],
            'mouvements' => [
                'periode'       => ['debut' => $start->format('d/m/Y'), 'fin' => $end->format('d/m/Y')],
                'nb_entrees'    => $entrees->count(),
                'total_entrees' => $entrees->sum('quantity'),
                'valeur_entrees'=> $entrees->sum(fn($e) => $e->quantity * $e->purchase_price),
                'nb_sorties'    => $sorties->count(),
                'total_sorties' => $sorties->sum('quantity'),
                'entrees'       => $entrees->map(fn($e) => [
                    'date'     => $e->created_at->format('d/m/Y'),
                    'product'  => $e->product?->name,
                    'quantity' => $e->quantity,
                    'prix'     => $e->purchase_price,
                    'fournisseur' => $e->supplier,
                ])->toArray(),
                'sorties_par_motif' => $sorties->groupBy('reason')->map(fn($g, $r) => [
                    'motif'    => $r,
                    'count'    => $g->count(),
                    'quantite' => $g->sum('quantity'),
                ])->toArray(),
            ],
            'alertes' => [
                'rupture'   => $rupture->map(fn($p) => ['name' => $p->name, 'category' => $p->category?->name])->toArray(),
                'stock_bas' => $stockBas->map(fn($p) => [
                    'name'     => $p->name,
                    'category' => $p->category?->name,
                    'quantity' => $p->stock?->quantity,
                    'seuil'    => $p->min_stock_alert,
                ])->toArray(),
            ],
        ];
    }
```

- [ ] **Step 4: Ajouter les routes**

Après `Route::get('stock', [ReportController::class, 'stock']);` :
```php
            Route::get('stock/pdf', [ReportController::class, 'stockPdf']);
            Route::get('stock/excel', [ReportController::class, 'stockExcel']);
```

- [ ] **Step 5: Ajouter les 2 actions**

Après `stock()` :
```php
    public function stockPdf(Request $request)
    {
        $data = $this->computeStockReport($request);

        return Pdf::loadView('reports.stock', $data)
            ->stream('rapport-stock-' . now()->format('Y-m-d') . '.pdf');
    }

    public function stockExcel(Request $request)
    {
        $data = $this->computeStockReport($request);

        $entrees = collect($data['mouvements']['entrees'])->map(fn($e) => [
            'Date'        => $e['date'],
            'Produit'     => $e['product'],
            'Quantité'    => $e['quantity'],
            'Prix achat'  => $e['prix'],
            'Fournisseur' => $e['fournisseur'] ?? '—',
        ]);

        $alertes = collect(array_merge(
            array_map(fn($p) => ['Produit' => $p['name'], 'Catégorie' => $p['category'] ?? '—', 'Statut' => 'Rupture', 'Quantité' => 0, 'Seuil' => '—'], $data['alertes']['rupture']),
            array_map(fn($p) => ['Produit' => $p['name'], 'Catégorie' => $p['category'] ?? '—', 'Statut' => 'Stock bas', 'Quantité' => $p['quantity'], 'Seuil' => $p['seuil']], $data['alertes']['stock_bas'])
        ));

        $sheets = new \Rap2hpoutre\FastExcel\SheetCollection([
            'Entrées' => $entrees,
            'Alertes' => $alertes,
        ]);

        return (new FastExcel($sheets))->download('rapport-stock-' . now()->format('Y-m-d') . '.xlsx');
    }
```

- [ ] **Step 6: Créer la vue PDF**

`backend/resources/views/reports/stock.blade.php` :
```blade
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; padding: 24px; }
  h1 { font-size: 18px; margin-bottom: 2px; }
  h2 { font-size: 13px; margin: 16px 0 6px; }
  .sub { color: #555; margin-bottom: 16px; }
  .kpis { display: table; width: 100%; margin-bottom: 16px; }
  .kpi { display: table-cell; width: 33.33%; padding: 8px 10px; border: 1px solid #ddd; }
  .kpi .label { font-size: 9px; color: #777; text-transform: uppercase; }
  .kpi .value { font-size: 15px; font-weight: bold; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  th, td { padding: 5px 6px; border-bottom: 1px solid #eee; text-align: left; font-size: 10px; }
  th { background: #f5f5f5; text-transform: uppercase; font-size: 8px; color: #555; }
  td.r, th.r { text-align: right; }
</style>
</head>
<body>
  <h1>Boutique D — Rapport de stock</h1>
  <div class="sub">Mouvements du {{ $mouvements['periode']['debut'] }} au {{ $mouvements['periode']['fin'] }}</div>

  <div class="kpis">
    <div class="kpi"><div class="label">Valeur stock (achat)</div><div class="value">{{ number_format($valeur_stock['achat'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Valeur stock (vente)</div><div class="value">{{ number_format($valeur_stock['vente'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Marge brute</div><div class="value">{{ number_format($valeur_stock['marge_brute'], 0, ',', ' ') }} F ({{ $valeur_stock['marge_pct'] }}%)</div></div>
  </div>

  <h2>Entrées de stock ({{ $mouvements['nb_entrees'] }})</h2>
  <table>
    <thead><tr><th>Date</th><th>Produit</th><th class="r">Quantité</th><th class="r">Prix achat</th><th>Fournisseur</th></tr></thead>
    <tbody>
      @foreach ($mouvements['entrees'] as $e)
      <tr><td>{{ $e['date'] }}</td><td>{{ $e['product'] }}</td><td class="r">{{ $e['quantity'] }}</td><td class="r">{{ number_format($e['prix'], 0, ',', ' ') }} F</td><td>{{ $e['fournisseur'] ?? '—' }}</td></tr>
      @endforeach
    </tbody>
  </table>

  <h2>Alertes ({{ count($alertes['rupture']) + count($alertes['stock_bas']) }})</h2>
  <table>
    <thead><tr><th>Produit</th><th>Catégorie</th><th>Statut</th><th class="r">Quantité</th></tr></thead>
    <tbody>
      @foreach ($alertes['rupture'] as $p)
      <tr><td>{{ $p['name'] }}</td><td>{{ $p['category'] ?? '—' }}</td><td>Rupture</td><td class="r">0</td></tr>
      @endforeach
      @foreach ($alertes['stock_bas'] as $p)
      <tr><td>{{ $p['name'] }}</td><td>{{ $p['category'] ?? '—' }}</td><td>Stock bas</td><td class="r">{{ $p['quantity'] }} / {{ $p['seuil'] }}</td></tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
```

- [ ] **Step 7: Vérifier le passage**

Run: `php artisan test --filter=ReportExportTest` — Attendu : 7 PASS.
Run: `php artisan test` — Attendu : tous PASS (24 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/ReportController.php routes/api.php resources/views/reports/stock.blade.php tests/Feature/ReportExportTest.php
git commit -m "feat: export PDF et Excel du rapport stock"
```

---

### Task 3: Rapport Trésorerie — refactor + PDF + Excel (TDD)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/ReportController.php` (méthode `treasury()`, lignes 172-225)
- Modify: `backend/routes/api.php`
- Create: `backend/resources/views/reports/treasury.blade.php`
- Test: `backend/tests/Feature/ReportExportTest.php` (ajout)

**Interfaces:**
- Produces: `ReportController::computeTreasuryReport(Request $request): array` — même forme que le retour actuel de `treasury()` (clés `periode`, `encaissements`, `sessions`).

- [ ] **Step 1: Ajouter les tests qui échouent**

Ajouter dans `ReportExportTest.php` :
```php
    // ── Trésorerie ───────────────────────────────────────────────────────

    public function test_le_rapport_tresorerie_json_est_inchange_apres_le_refactor(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500);
        $this->makeSaleViaApi($owner, $product, qty: 2);

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/reports/treasury?period=today');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(1000, $data['encaissements']['especes']);
        $this->assertArrayHasKey('sessions', $data);
    }

    public function test_le_pdf_du_rapport_tresorerie_est_genere(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $response = $this->get('/api/reports/treasury/pdf?period=today');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_l_excel_du_rapport_tresorerie_est_genere(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $response = $this->get('/api/reports/treasury/excel?period=today');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
    }
```

- [ ] **Step 2: Vérifier l'échec**

Run: `php artisan test --filter=ReportExportTest`
Attendu : le test JSON trésorerie PASSE ; PDF et Excel FAIL (404).

- [ ] **Step 3: Refactorer `treasury()` en `computeTreasuryReport()`**

Remplacer la méthode `treasury()` (lignes 172-225) par :
```php
    public function treasury(Request $request): JsonResponse
    {
        return $this->success($this->computeTreasuryReport($request));
    }

    private function computeTreasuryReport(Request $request): array
    {
        [$start, $end] = $this->resolvePeriod($request);

        $sales = Sale::whereBetween('created_at', [$start, $end])->get();

        $especes     = $sales->where('payment_method', 'especes')->sum('total');
        $mobileMoney = $sales->where('payment_method', 'mobile_money')->sum('total');
        $totalEncaisse = $especes + $mobileMoney;

        $remboursements = Refund::whereHas('sale', fn($q) =>
            $q->whereBetween('created_at', [$start, $end])
        )->sum('amount');

        $sessions = CashSession::whereBetween('opened_at', [$start, $end])
            ->with('cashier:id,name')
            ->get();

        $seuilCaisse = (int) Setting::getValue('ecart_caisse_alerte', 2000);
        $sessionsAvecEcart = $sessions->filter(fn($s) =>
            !is_null($s->difference) && abs($s->difference) > $seuilCaisse
        );

        return [
            'periode'       => ['debut' => $start->format('d/m/Y'), 'fin' => $end->format('d/m/Y')],
            'encaissements' => [
                'especes'        => $especes,
                'mobile_money'   => $mobileMoney,
                'total'          => $totalEncaisse,
                'remboursements' => $remboursements,
                'net'            => $totalEncaisse - $remboursements,
            ],
            'sessions' => [
                'total'          => $sessions->count(),
                'avec_ecart'     => $sessionsAvecEcart->count(),
                'total_ecarts'   => $sessionsAvecEcart->sum('difference'),
                'detail'         => $sessions->map(fn($s) => [
                    'cashier'      => $s->cashier?->name,
                    'ouverture'    => $s->opened_at->format('d/m/Y H:i'),
                    'fermeture'    => $s->closed_at?->format('d/m/Y H:i'),
                    'fonds_depart' => $s->opening_amount,
                    'montant_saisi'=> $s->closing_amount,
                    'theorique'    => $s->theoretical_amount,
                    'ecart'        => $s->difference,
                    'statut'       => $s->isOpen() ? 'ouverte' : (
                        abs($s->difference ?? 0) > $seuilCaisse ? 'ecart' : 'ok'
                    ),
                ])->toArray(),
            ],
        ];
    }
```

- [ ] **Step 4: Ajouter les routes**

Après `Route::get('treasury', [ReportController::class, 'treasury']);` :
```php
            Route::get('treasury/pdf', [ReportController::class, 'treasuryPdf']);
            Route::get('treasury/excel', [ReportController::class, 'treasuryExcel']);
```

- [ ] **Step 5: Ajouter les 2 actions**

Après `treasury()` :
```php
    public function treasuryPdf(Request $request)
    {
        $data = $this->computeTreasuryReport($request);

        return Pdf::loadView('reports.treasury', $data)
            ->stream('rapport-tresorerie-' . now()->format('Y-m-d') . '.pdf');
    }

    public function treasuryExcel(Request $request)
    {
        $data = $this->computeTreasuryReport($request);

        $rows = collect($data['sessions']['detail'])->map(fn($s) => [
            'Caissier'       => $s['cashier'] ?? '—',
            'Ouverture'      => $s['ouverture'],
            'Fermeture'      => $s['fermeture'] ?? '—',
            'Fonds départ'   => $s['fonds_depart'],
            'Théorique'      => $s['theorique'] ?? '—',
            'Saisi'          => $s['montant_saisi'] ?? '—',
            'Écart'          => $s['ecart'] ?? '—',
            'Statut'         => $s['statut'],
        ]);

        return (new FastExcel($rows))->download('rapport-tresorerie-' . now()->format('Y-m-d') . '.xlsx');
    }
```

- [ ] **Step 6: Créer la vue PDF**

`backend/resources/views/reports/treasury.blade.php` :
```blade
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; padding: 24px; }
  h1 { font-size: 18px; margin-bottom: 2px; }
  .sub { color: #555; margin-bottom: 16px; }
  .kpis { display: table; width: 100%; margin-bottom: 16px; }
  .kpi { display: table-cell; width: 25%; padding: 8px 10px; border: 1px solid #ddd; }
  .kpi .label { font-size: 9px; color: #777; text-transform: uppercase; }
  .kpi .value { font-size: 15px; font-weight: bold; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { padding: 5px 6px; border-bottom: 1px solid #eee; text-align: left; font-size: 10px; }
  th { background: #f5f5f5; text-transform: uppercase; font-size: 8px; color: #555; }
  td.r, th.r { text-align: right; }
</style>
</head>
<body>
  <h1>Boutique D — Rapport de trésorerie</h1>
  <div class="sub">Période du {{ $periode['debut'] }} au {{ $periode['fin'] }}</div>

  <div class="kpis">
    <div class="kpi"><div class="label">Espèces</div><div class="value">{{ number_format($encaissements['especes'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Mobile Money</div><div class="value">{{ number_format($encaissements['mobile_money'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Remboursements</div><div class="value">{{ number_format($encaissements['remboursements'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Net</div><div class="value">{{ number_format($encaissements['net'], 0, ',', ' ') }} F</div></div>
  </div>

  <table>
    <thead>
      <tr><th>Caissier</th><th>Ouverture</th><th>Fermeture</th><th class="r">Fonds départ</th><th class="r">Théorique</th><th class="r">Saisi</th><th class="r">Écart</th><th>Statut</th></tr>
    </thead>
    <tbody>
      @foreach ($sessions['detail'] as $s)
      <tr>
        <td>{{ $s['cashier'] ?? '—' }}</td>
        <td>{{ $s['ouverture'] }}</td>
        <td>{{ $s['fermeture'] ?? '—' }}</td>
        <td class="r">{{ number_format($s['fonds_depart'], 0, ',', ' ') }} F</td>
        <td class="r">{{ $s['theorique'] !== null ? number_format($s['theorique'], 0, ',', ' ') . ' F' : '—' }}</td>
        <td class="r">{{ $s['montant_saisi'] !== null ? number_format($s['montant_saisi'], 0, ',', ' ') . ' F' : '—' }}</td>
        <td class="r">{{ $s['ecart'] !== null ? number_format($s['ecart'], 0, ',', ' ') . ' F' : '—' }}</td>
        <td>{{ ucfirst($s['statut']) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
```

- [ ] **Step 7: Vérifier le passage**

Run: `php artisan test --filter=ReportExportTest` — Attendu : 10 PASS.
Run: `php artisan test` — Attendu : tous PASS (27 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/ReportController.php routes/api.php resources/views/reports/treasury.blade.php tests/Feature/ReportExportTest.php
git commit -m "feat: export PDF et Excel du rapport tresorerie"
```

---

### Task 4: Rapport Employés — refactor + PDF + Excel (TDD)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/ReportController.php` (méthode `employees()`, lignes 226-274)
- Modify: `backend/routes/api.php`
- Create: `backend/resources/views/reports/employees.blade.php`
- Test: `backend/tests/Feature/ReportExportTest.php` (ajout)

**Interfaces:**
- Produces: `ReportController::computeEmployeesReport(Request $request): array` — même forme que le retour actuel de `employees()` (clés `periode`, `employes`).

- [ ] **Step 1: Ajouter les tests qui échouent**

Ajouter dans `ReportExportTest.php` :
```php
    // ── Employés ─────────────────────────────────────────────────────────

    public function test_le_rapport_employes_json_est_inchange_apres_le_refactor(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500);
        $this->makeSaleViaApi($owner, $product, qty: 1);

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/reports/employees?period=today');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayHasKey('employes', $data);
        $employe = collect($data['employes'])->firstWhere('id', $owner->id);
        $this->assertSame(1, $employe['nb_ventes']);
    }

    public function test_le_pdf_du_rapport_employes_est_genere(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $response = $this->get('/api/reports/employees/pdf?period=today');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_l_excel_du_rapport_employes_est_genere(): void
    {
        $owner = $this->makeUser('proprietaire');

        Sanctum::actingAs($owner);
        $response = $this->get('/api/reports/employees/excel?period=today');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
    }
```

- [ ] **Step 2: Vérifier l'échec**

Run: `php artisan test --filter=ReportExportTest`
Attendu : le test JSON employés PASSE ; PDF et Excel FAIL (404).

- [ ] **Step 3: Refactorer `employees()` en `computeEmployeesReport()`**

Remplacer la méthode `employees()` (lignes 226-274) par :
```php
    public function employees(Request $request): JsonResponse
    {
        return $this->success($this->computeEmployeesReport($request));
    }

    private function computeEmployeesReport(Request $request): array
    {
        [$start, $end] = $this->resolvePeriod($request);

        $employes = User::whereHas('roles', fn($q) =>
            $q->whereIn('name', ['caissier', 'vendeur', 'gestionnaire', 'proprietaire'])
        )
        ->where('is_active', true)
        ->get();

        $stats = $employes->map(function ($user) use ($start, $end) {
            $sales = Sale::where('cashier_id', $user->id)
                ->whereBetween('created_at', [$start, $end])
                ->get();

            $refunds = Refund::where('cashier_id', $user->id)
                ->whereBetween('created_at', [$start, $end])
                ->get();

            $sessions = CashSession::where('cashier_id', $user->id)
                ->whereBetween('opened_at', [$start, $end])
                ->get();

            $heuresConnexion = $sessions->sum(function ($s) {
                $fin = $s->closed_at ?? now();
                return $s->opened_at->diffInMinutes($fin);
            });

            return [
                'id'               => $user->id,
                'name'             => $user->name,
                'role'             => $user->getRoleNames()->first(),
                'nb_ventes'        => $sales->count(),
                'montant_vendu'    => $sales->sum('total'),
                'panier_moyen'     => $sales->count() > 0
                    ? (int) round($sales->sum('total') / $sales->count()) : 0,
                'nb_remboursements'=> $refunds->count(),
                'montant_rembourse'=> $refunds->sum('amount'),
                'nb_sessions'      => $sessions->count(),
                'heures_connexion' => round($heuresConnexion / 60, 1),
            ];
        })->sortByDesc('montant_vendu')->values();

        return [
            'periode'  => ['debut' => $start->format('d/m/Y'), 'fin' => $end->format('d/m/Y')],
            'employes' => $stats->toArray(),
        ];
    }
```

- [ ] **Step 4: Ajouter les routes**

Après `Route::get('employees', [ReportController::class, 'employees']);` :
```php
            Route::get('employees/pdf', [ReportController::class, 'employeesPdf']);
            Route::get('employees/excel', [ReportController::class, 'employeesExcel']);
```

- [ ] **Step 5: Ajouter les 2 actions**

Après `employees()` :
```php
    public function employeesPdf(Request $request)
    {
        $data = $this->computeEmployeesReport($request);

        return Pdf::loadView('reports.employees', $data)
            ->stream('rapport-employes-' . now()->format('Y-m-d') . '.pdf');
    }

    public function employeesExcel(Request $request)
    {
        $data = $this->computeEmployeesReport($request);

        $rows = collect($data['employes'])->map(fn($e) => [
            'Employé'          => $e['name'],
            'Rôle'             => $e['role'],
            'Ventes'           => $e['nb_ventes'],
            'CA'               => $e['montant_vendu'],
            'Panier moyen'     => $e['panier_moyen'],
            'Remboursements'   => $e['nb_remboursements'],
            'Sessions'         => $e['nb_sessions'],
            'Heures'           => $e['heures_connexion'],
        ]);

        return (new FastExcel($rows))->download('rapport-employes-' . now()->format('Y-m-d') . '.xlsx');
    }
```

- [ ] **Step 6: Créer la vue PDF**

`backend/resources/views/reports/employees.blade.php` :
```blade
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; padding: 24px; }
  h1 { font-size: 18px; margin-bottom: 2px; }
  .sub { color: #555; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { padding: 5px 6px; border-bottom: 1px solid #eee; text-align: left; font-size: 10px; }
  th { background: #f5f5f5; text-transform: uppercase; font-size: 8px; color: #555; }
  td.r, th.r { text-align: right; }
</style>
</head>
<body>
  <h1>Boutique D — Rapport performance employés</h1>
  <div class="sub">Période du {{ $periode['debut'] }} au {{ $periode['fin'] }}</div>

  <table>
    <thead>
      <tr><th>Employé</th><th>Rôle</th><th class="r">Ventes</th><th class="r">CA</th><th class="r">Panier moy.</th><th class="r">Remboursements</th><th class="r">Sessions</th><th class="r">Heures</th></tr>
    </thead>
    <tbody>
      @foreach ($employes as $e)
      <tr>
        <td>{{ $e['name'] }}</td>
        <td>{{ $e['role'] }}</td>
        <td class="r">{{ $e['nb_ventes'] }}</td>
        <td class="r">{{ number_format($e['montant_vendu'], 0, ',', ' ') }} F</td>
        <td class="r">{{ number_format($e['panier_moyen'], 0, ',', ' ') }} F</td>
        <td class="r">{{ $e['nb_remboursements'] }}</td>
        <td class="r">{{ $e['nb_sessions'] }}</td>
        <td class="r">{{ $e['heures_connexion'] }}h</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
```

- [ ] **Step 7: Vérifier le passage**

Run: `php artisan test --filter=ReportExportTest` — Attendu : 13 PASS.
Run: `php artisan test` — Attendu : tous PASS (30 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/ReportController.php routes/api.php resources/views/reports/employees.blade.php tests/Feature/ReportExportTest.php
git commit -m "feat: export PDF et Excel du rapport employes"
```

---

### Task 5: Frontend — boutons d'export sur les 4 onglets

**Files:**
- Modify: `frontend/js/app.js` — nouveau helper `downloadFile`
- Modify: `frontend/reports.html` — boutons PDF/Excel

**Interfaces:**
- Consumes: les 8 routes des Tasks 1-4 (`GET /reports/<nom>/pdf|excel`), `API_BASE` (constante globale de `api.js`), `toast()`.
- Produces: `downloadFile(url, filename)` — helper global réutilisable par toute page future ayant besoin de télécharger un fichier authentifié (même pattern que `openReceiptPdf` de la Phase B1, généralisé).

- [ ] **Step 1: Ajouter le helper dans `app.js`**

Dans `frontend/js/app.js`, ajouter après la fonction `toast()` :
```javascript
// ── Téléchargement de fichier authentifié (PDF, Excel) ──────────────────────
async function downloadFile(url, filename) {
  try {
    const res = await fetch(url, {
      headers: { Authorization: `Bearer ${localStorage.getItem('token')}` },
    });
    if (!res.ok) throw new Error(`Erreur ${res.status}`);
    const blob = await res.blob();
    const objectUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = objectUrl;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(objectUrl);
  } catch (err) {
    toast(err.message, 'danger');
  }
}
```
(Contrairement à `openReceiptPdf` de la Phase B1 qui ouvre un nouvel onglet, ce helper déclenche un téléchargement direct via `<a download>` — plus adapté à des rapports que l'utilisateur veut conserver sur disque, et évite le risque de bloqueur de popup identifié en Phase B1.)

- [ ] **Step 2: Ajouter les boutons — onglet Ventes**

Dans `frontend/reports.html`, dans le `.card-header` de la carte "Liste des ventes" (~ligne 162-166), remplacer :
```html
        <div class="card-header">
          <div class="card-title">
            <i data-lucide="list" class="icon" style="color:var(--accent)"></i> Liste des ventes
          </div>
        </div>
```
par :
```html
        <div class="card-header">
          <div class="card-title">
            <i data-lucide="list" class="icon" style="color:var(--accent)"></i> Liste des ventes
          </div>
          <div class="flex gap-2">
            <button class="btn btn-sm" onclick="exportSales('pdf')"><i data-lucide="file-text" class="icon"></i> PDF</button>
            <button class="btn btn-sm" onclick="exportSales('excel')"><i data-lucide="file-spreadsheet" class="icon"></i> Excel</button>
          </div>
        </div>
```

Ajouter dans le script inline, juste après `loadSales()` :
```javascript
function exportSales(format) {
  const period = document.getElementById('s-period').value;
  const params = new URLSearchParams({ period });
  if (period === 'custom') {
    params.set('start_date', document.getElementById('s-start').value);
    params.set('end_date',   document.getElementById('s-end').value);
  }
  const saleType  = document.getElementById('s-type').value;
  const payMethod = document.getElementById('s-pay').value;
  if (saleType)  params.set('sale_type', saleType);
  if (payMethod) params.set('payment_method', payMethod);
  const ext = format === 'pdf' ? 'pdf' : 'xlsx';
  downloadFile(`${API_BASE}/reports/sales/${format}?${params}`, `rapport-ventes.${ext}`);
}
```

- [ ] **Step 3: Onglet Trésorerie**

Dans le `.card-header` de "Détail des sessions de caisse" (~ligne 199-203), même transformation :
```html
        <div class="card-header">
          <div class="card-title">
            <i data-lucide="store" class="icon" style="color:var(--accent)"></i> Détail des sessions de caisse
          </div>
          <div class="flex gap-2">
            <button class="btn btn-sm" onclick="exportTreasury('pdf')"><i data-lucide="file-text" class="icon"></i> PDF</button>
            <button class="btn btn-sm" onclick="exportTreasury('excel')"><i data-lucide="file-spreadsheet" class="icon"></i> Excel</button>
          </div>
        </div>
```

JS, après `loadTreasury()` :
```javascript
function exportTreasury(format) {
  const period = document.getElementById('t-period').value;
  const ext = format === 'pdf' ? 'pdf' : 'xlsx';
  downloadFile(`${API_BASE}/reports/treasury/${format}?period=${period}`, `rapport-tresorerie.${ext}`);
}
```

- [ ] **Step 4: Onglet Stock**

Le panneau Stock n'a pas de `.card-header` avec titre unique (deux cartes côte à côte). Ajouter une barre d'action juste après `<div class="kpi-grid mb-4" id="stock-kpis"></div>` (~ligne 224) :
```html
      <div class="kpi-grid mb-4" id="stock-kpis"></div>
      <div class="flex gap-2 mb-4">
        <button class="btn btn-sm" onclick="exportStock('pdf')"><i data-lucide="file-text" class="icon"></i> Export PDF</button>
        <button class="btn btn-sm" onclick="exportStock('excel')"><i data-lucide="file-spreadsheet" class="icon"></i> Export Excel</button>
      </div>
```

JS, après `loadStockReport()` :
```javascript
function exportStock(format) {
  const ext = format === 'pdf' ? 'pdf' : 'xlsx';
  downloadFile(`${API_BASE}/reports/stock/${format}`, `rapport-stock.${ext}`);
}
```

- [ ] **Step 5: Onglet Employés**

Le panneau Employés a une `.card` sans `.card-header` (table directe). Ajouter une barre d'action juste après le `.filters-bar` de l'onglet employés (~ligne 259, avant `<div class="card">`) :
```html
      <div class="flex gap-2 mb-4">
        <button class="btn btn-sm" onclick="exportEmployees('pdf')"><i data-lucide="file-text" class="icon"></i> Export PDF</button>
        <button class="btn btn-sm" onclick="exportEmployees('excel')"><i data-lucide="file-spreadsheet" class="icon"></i> Export Excel</button>
      </div>
```

JS, après `loadEmployees()` :
```javascript
function exportEmployees(format) {
  const period = document.getElementById('e-period').value;
  const ext = format === 'pdf' ? 'pdf' : 'xlsx';
  downloadFile(`${API_BASE}/reports/employees/${format}?period=${period}`, `rapport-employes.${ext}`);
}
```

- [ ] **Step 6: Vérification statique**

1. `grep -n "downloadFile" frontend/js/app.js` → 1 définition.
2. `grep -c "onclick=\"export" frontend/reports.html` → 8 (2 boutons × 4 onglets).
3. `node --check` sur le JS inline extrait de `reports.html`.
4. Icônes `file-text` et `file-spreadsheet` sont des noms Lucide 0.462.0 valides (à vérifier contre le registre officiel si un doute).

- [ ] **Step 7: Vérification manuelle**

1. Serveurs démarrés, login `admin`/`admin123`, aller sur Rapports.
2. Onglet Ventes → bouton PDF → un fichier `rapport-ventes.pdf` se télécharge, s'ouvre correctement (montants, tableau).
3. Onglet Ventes → bouton Excel → un fichier `.xlsx` se télécharge, s'ouvre dans un tableur, colonnes lisibles.
4. Répéter pour Trésorerie, Stock (vérifier les 2 feuilles "Entrées"/"Alertes" dans le classeur Excel), Employés.
5. Changer la période/les filtres avant d'exporter → le fichier reflète bien les filtres appliqués (comparer avec le tableau affiché à l'écran).

- [ ] **Step 8: Commit**

```bash
git add frontend/js/app.js frontend/reports.html
git commit -m "feat: boutons d'export PDF et Excel sur les 4 onglets de rapports"
```

---

### Task 6: Vérification finale du module

**Files:** aucun changement de code — vérification uniquement.

- [ ] **Step 1: Suite complète**

Run: `cd backend && php artisan test` — Attendu : tous PASS (30 tests, sortie propre, sans warning).

- [ ] **Step 2: Vérification manuelle croisée**

Pour chaque rapport (ventes, stock, trésorerie, employés) : comparer un total affiché à l'écran (ex. CA Total) avec le total calculable à la main depuis le fichier Excel exporté correspondant — doivent correspondre exactement.

- [ ] **Step 3: Non-régression Phase B1**

Vérifier qu'une vente + un remboursement + un reçu PDF (Phase B1) fonctionnent toujours normalement (aucun fichier de B1 modifié par ce plan, vérification de confiance).

- [ ] **Step 4: Commit (si des ajustements ont été faits)**

Sinon, rien à commit — cette tâche est une passe de vérification qui clôt le module avant la revue finale de branche.
