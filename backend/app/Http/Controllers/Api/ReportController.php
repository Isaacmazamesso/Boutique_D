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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    // ── Rapport ventes ────────────────────────────────────────────────────────

    public function sales(Request $request): JsonResponse
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

        return $this->success([
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
            ]),
        ]);
    }

    // ── Rapport stock ─────────────────────────────────────────────────────────

    public function stock(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $start = $request->start_date ? now()->parse($request->start_date)->startOfDay() : today()->startOfMonth();
        $end   = $request->end_date   ? now()->parse($request->end_date)->endOfDay()     : now();

        // Valeur du stock actuel
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

        // Mouvements sur la période
        $entrees = StockEntry::whereBetween('created_at', [$start, $end])
            ->with('product:id,name')
            ->get();

        $sorties = StockExit::whereBetween('created_at', [$start, $end])
            ->with('product:id,name')
            ->get();

        // Produits en rupture ou stock bas
        $rupture  = $produits->filter(fn($p) => $p->stockStatus() === 'rupture')->values();
        $stockBas = $produits->filter(fn($p) => $p->stockStatus() === 'bas')->values();

        return $this->success([
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
                ]),
                'sorties_par_motif' => $sorties->groupBy('reason')->map(fn($g, $r) => [
                    'motif'    => $r,
                    'count'    => $g->count(),
                    'quantite' => $g->sum('quantity'),
                ]),
            ],
            'alertes' => [
                'rupture'   => $rupture->map(fn($p) => ['name' => $p->name, 'category' => $p->category?->name]),
                'stock_bas' => $stockBas->map(fn($p) => [
                    'name'     => $p->name,
                    'category' => $p->category?->name,
                    'quantity' => $p->stock?->quantity,
                    'seuil'    => $p->min_stock_alert,
                ]),
            ],
        ]);
    }

    // ── Rapport trésorerie ────────────────────────────────────────────────────

    public function treasury(Request $request): JsonResponse
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

        return $this->success([
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
                ]),
            ],
        ]);
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
