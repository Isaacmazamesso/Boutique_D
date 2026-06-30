<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $today     = today();
        $startWeek = today()->startOfWeek();
        $startMonth= today()->startOfMonth();

        // ── Chiffres d'affaires ───────────────────────────────────────────────
        $caJour   = Sale::whereDate('created_at', $today)->sum('total');
        $caSemaine= Sale::whereBetween('created_at', [$startWeek, now()])->sum('total');
        $caMois   = Sale::whereBetween('created_at', [$startMonth, now()])->sum('total');

        $nbVentesJour = Sale::whereDate('created_at', $today)->count();

        // ── Bénéfice estimé du jour ───────────────────────────────────────────
        // CA jour - coût d'achat (prix achat actuel × quantités vendues)
        $coutJour = SaleItem::whereHas('sale', fn($q) =>
                $q->whereDate('created_at', $today)
            )
            ->join('product_prices', 'sale_items.product_id', '=', 'product_prices.product_id')
            ->sum(DB::raw('sale_items.quantity * product_prices.purchase_price'));

        $beneficeJour = $caJour - $coutJour;

        // ── Top 5 produits du jour ────────────────────────────────────────────
        $top5 = SaleItem::selectRaw('product_id, SUM(quantity) as total_qty, SUM(total) as total_ca')
            ->whereHas('sale', fn($q) => $q->whereDate('created_at', $today))
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->with('product:id,name,unit')
            ->get()
            ->map(fn($i) => [
                'product'   => $i->product?->name,
                'unit'      => $i->product?->unit,
                'total_qty' => $i->total_qty,
                'total_ca'  => $i->total_ca,
            ]);

        // ── Statut des caissiers ──────────────────────────────────────────────
        $caissiers = User::role('caissier')
            ->where('is_active', true)
            ->get()
            ->map(function ($user) {
                $hasToken   = $user->tokens()->where('last_used_at', '>=', now()->subHour())->exists();
                $openSession= CashSession::where('cashier_id', $user->id)
                    ->whereNull('closed_at')
                    ->exists();
                return [
                    'id'           => $user->id,
                    'name'         => $user->name,
                    'en_ligne'     => $hasToken,
                    'caisse_ouverte' => $openSession,
                ];
            });

        // ── Alertes actives ───────────────────────────────────────────────────
        $nbStockBas  = Product::where('is_active', true)
            ->whereHas('stock', fn($q) =>
                $q->whereRaw('quantity > 0 AND quantity <= products.min_stock_alert')
            )->count();

        $nbRupture = Product::where('is_active', true)
            ->whereHas('stock', fn($q) => $q->where('quantity', '<=', 0))
            ->count();

        $joursAlerte = (int) Setting::getValue('peremption_alerte_jours', 7);
        $nbPeremption= Product::where('is_active', true)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($joursAlerte))
            ->whereDate('expiry_date', '>=', today())
            ->count();

        // Écarts de caisse aujourd'hui
        $seuilCaisse = (int) Setting::getValue('ecart_caisse_alerte', 2000);
        $nbEcartsCaisse = CashSession::whereDate('closed_at', $today)
            ->whereRaw('ABS(difference) > ?', [$seuilCaisse])
            ->count();

        // ── Comparaison hier ──────────────────────────────────────────────────
        $hier = today()->subDay();
        $caHier = Sale::whereDate('created_at', $hier)->sum('total');
        $evolutionPct = $caHier > 0
            ? round(($caJour - $caHier) / $caHier * 100, 1)
            : null;

        return $this->success([
            'ca' => [
                'jour'        => $caJour,
                'semaine'     => $caSemaine,
                'mois'        => $caMois,
                'hier'        => $caHier,
                'evolution_pct' => $evolutionPct,
            ],
            'ventes' => [
                'nb_jour'     => $nbVentesJour,
                'panier_moyen'=> $nbVentesJour > 0 ? (int) round($caJour / $nbVentesJour) : 0,
            ],
            'benefice' => [
                'jour'        => $beneficeJour,
                'marge_pct'   => $caJour > 0 ? round($beneficeJour / $caJour * 100, 1) : 0,
            ],
            'top5_produits' => $top5,
            'caissiers'     => $caissiers,
            'alertes' => [
                'stock_bas'     => $nbStockBas,
                'rupture'       => $nbRupture,
                'peremption'    => $nbPeremption,
                'ecarts_caisse' => $nbEcartsCaisse,
                'total'         => $nbStockBas + $nbRupture + $nbPeremption + $nbEcartsCaisse,
            ],
        ]);
    }

    private function success(mixed $data, string $message = 'OK'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }
}
