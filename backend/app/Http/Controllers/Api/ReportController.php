<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockEntry;
use App\Models\StockExit;
use App\Models\Setting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Rap2hpoutre\FastExcel\FastExcel;

class ReportController extends Controller
{
    // ── Rapport ventes ────────────────────────────────────────────────────────

    public function sales(Request $request): JsonResponse
    {
        return $this->success($this->computeSalesReport($request));
    }

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

        $response = (new FastExcel($rows))->download('rapport-ventes-' . now()->format('Y-m-d') . '.xlsx');
        // FastExcel/OpenSpout set le Content-Type via header() natif au moment du stream,
        // ce qui n'apparaît pas dans le header bag de la Response (donc invisible en test HTTP) :
        // on le fixe explicitement pour que le client (et les tests) le voient de façon fiable.
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        return $response;
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

    // ── Rapport stock ─────────────────────────────────────────────────────────

    public function stock(Request $request): JsonResponse
    {
        return $this->success($this->computeStockReport($request));
    }

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

        $response = (new FastExcel($sheets))->download('rapport-stock-' . now()->format('Y-m-d') . '.xlsx');
        // FastExcel/OpenSpout set le Content-Type via header() natif au moment du stream,
        // ce qui n'apparaît pas dans le header bag de la Response (invisible en test HTTP) :
        // on le fixe explicitement (confirmé nécessaire en B2-T1, vendor/rap2hpoutre/fast-excel/src/Exportable.php:78-88).
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        return $response;
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

    // ── Rapport trésorerie ────────────────────────────────────────────────────

    public function treasury(Request $request): JsonResponse
    {
        return $this->success($this->computeTreasuryReport($request));
    }

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

        $response = (new FastExcel($rows))->download('rapport-tresorerie-' . now()->format('Y-m-d') . '.xlsx');
        // FastExcel/OpenSpout set le Content-Type via header() natif au moment du stream,
        // ce qui n'apparaît pas dans le header bag de la Response (invisible en test HTTP) :
        // on le fixe explicitement (confirmé nécessaire en B2-T1, vendor/rap2hpoutre/fast-excel/src/Exportable.php:78-88).
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        return $response;
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

    // ── Rapport performance employés ──────────────────────────────────────────

    public function employees(Request $request): JsonResponse
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

        return $this->success([
            'periode'  => ['debut' => $start->format('d/m/Y'), 'fin' => $end->format('d/m/Y')],
            'employes' => $stats,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolvePeriod(Request $request): array
    {
        return match ($request->period ?? 'today') {
            'week'   => [today()->startOfWeek(), now()],
            'month'  => [today()->startOfMonth(), now()],
            'custom' => [
                now()->parse($request->start_date)->startOfDay(),
                now()->parse($request->end_date)->endOfDay(),
            ],
            default  => [today()->startOfDay(), now()],
        };
    }

    private function success(mixed $data, string $message = 'OK'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }
}
