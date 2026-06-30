<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockEntry;
use App\Models\StockExit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    // Tableau de bord stock — tous les produits avec statut
    public function dashboard(Request $request): JsonResponse
    {
        $products = Product::with(['category', 'price', 'stock'])
            ->where('is_active', true)
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            ->orderBy('name')
            ->get();

        $grouped = [
            'rupture' => [],
            'bas'     => [],
            'normal'  => [],
        ];

        $totals = ['valeur_achat' => 0, 'valeur_vente' => 0, 'nb_produits' => 0];

        foreach ($products as $product) {
            $qty    = $product->stock?->quantity ?? 0;
            $status = $product->stockStatus();
            $pa     = $product->price?->purchase_price ?? 0;
            $pv     = $product->price?->retail_price ?? 0;

            $row = [
                'id'             => $product->id,
                'name'           => $product->name,
                'category'       => $product->category?->name,
                'unit'           => $product->unit,
                'quantity'       => $qty,
                'min_stock_alert'=> $product->min_stock_alert,
                'stock_status'   => $status,
                'purchase_price' => $pa,
                'retail_price'   => $pv,
                'valeur_stock'   => $qty * $pa,
                'expiry_date'    => $product->expiry_date?->format('Y-m-d'),
            ];

            $grouped[$status][] = $row;
            $totals['valeur_achat'] += $qty * $pa;
            $totals['valeur_vente'] += $qty * $pv;
            $totals['nb_produits']++;
        }

        return $this->success([
            'totaux'   => $totals,
            'rupture'  => $grouped['rupture'],
            'bas'      => $grouped['bas'],
            'normal'   => $grouped['normal'],
        ]);
    }

    // Alertes actives
    public function alerts(): JsonResponse
    {
        $alerts = [];
        $joursAlerte = (int) Setting::getValue('peremption_alerte_jours', 7);

        // Stock bas
        $stockBas = Product::with(['stock', 'category'])
            ->where('is_active', true)
            ->whereHas('stock', fn($q) =>
                $q->whereRaw('quantity > 0 AND quantity <= products.min_stock_alert')
            )
            ->get();

        foreach ($stockBas as $p) {
            $alerts[] = [
                'type'     => 'stock_bas',
                'product'  => $p->name,
                'category' => $p->category?->name,
                'quantity' => $p->stock->quantity,
                'seuil'    => $p->min_stock_alert,
            ];
        }

        // Rupture
        $rupture = Product::with(['stock', 'category'])
            ->where('is_active', true)
            ->whereHas('stock', fn($q) => $q->where('quantity', '<=', 0))
            ->get();

        foreach ($rupture as $p) {
            $alerts[] = [
                'type'     => 'rupture',
                'product'  => $p->name,
                'category' => $p->category?->name,
                'quantity' => 0,
                'seuil'    => $p->min_stock_alert,
            ];
        }

        // Péremption imminente
        $expiringSoon = Product::with('category')
            ->where('is_active', true)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($joursAlerte))
            ->whereDate('expiry_date', '>=', today())
            ->get();

        foreach ($expiringSoon as $p) {
            $alerts[] = [
                'type'          => 'peremption',
                'product'       => $p->name,
                'category'      => $p->category?->name,
                'expiry_date'   => $p->expiry_date->format('d/m/Y'),
                'jours_restants'=> today()->diffInDays($p->expiry_date),
            ];
        }

        return $this->success([
            'count'  => count($alerts),
            'alerts' => $alerts,
        ]);
    }

    // ── Entrées de stock ──────────────────────────────────────────────────────

    public function storeEntry(Request $request): JsonResponse
    {
        $request->validate([
            'product_id'     => 'required|exists:products,id',
            'quantity'       => 'required|integer|min:1',
            'purchase_price' => 'required|integer|min:0',
            'supplier'       => 'nullable|string|max:100',
            'invoice_number' => 'nullable|string|max:100',
            'expiry_date'    => 'nullable|date',
            'notes'          => 'nullable|string',
        ]);

        $entry = DB::transaction(function () use ($request) {
            $entry = StockEntry::create([
                'product_id'     => $request->product_id,
                'received_by'    => $request->user()->id,
                'quantity'       => $request->quantity,
                'purchase_price' => $request->purchase_price,
                'supplier'       => $request->supplier,
                'invoice_number' => $request->invoice_number,
                'expiry_date'    => $request->expiry_date,
                'notes'          => $request->notes,
            ]);

            // Incrémenter le stock
            $product = Product::with('stock')->find($request->product_id);
            $product->stock->increment('quantity', $request->quantity);

            // Mettre à jour le prix d'achat si différent
            if ($product->price && $request->purchase_price !== $product->price->purchase_price) {
                $product->price->update(['purchase_price' => $request->purchase_price]);
            }

            // Mettre à jour la date de péremption si fournie
            if ($request->expiry_date) {
                $product->update(['expiry_date' => $request->expiry_date]);
            }

            return $entry;
        });

        activity_log($request->user()->id, 'entree_stock', 'StockEntry', $entry->id, [
            'product_id' => $request->product_id,
            'quantity'   => $request->quantity,
        ]);

        $product = Product::with(['stock', 'category', 'price'])->find($request->product_id);

        return $this->success([
            'entry'           => $this->formatEntry($entry->load('product')),
            'nouveau_stock'   => $product->stock->quantity,
            'statut_stock'    => $product->stockStatus(),
        ], "Stock mis à jour. Nouveau stock : {$product->stock->quantity} {$product->unit}(s).", 201);
    }

    public function indexEntries(Request $request): JsonResponse
    {
        $entries = StockEntry::with(['product:id,name,unit', 'receivedBy:id,name'])
            ->when($request->product_id, fn($q) => $q->where('product_id', $request->product_id))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn($e) => $this->formatEntry($e));

        return $this->success($entries);
    }

    // ── Sorties manuelles ─────────────────────────────────────────────────────

    public function storeExit(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
            'reason'     => 'required|in:casse,peremption,usage_interne,perte,vol,autre',
            'notes'      => 'nullable|string',
        ]);

        $product = Product::with('stock')->find($request->product_id);

        // Vérifier stock disponible
        $stockQty = $product->stock?->quantity ?? 0;
        if ($request->quantity > $stockQty) {
            return $this->error(
                "Stock insuffisant : {$stockQty} {$product->unit}(s) disponible(s).",
                422
            );
        }

        // Vérifier autorisation si quantité > seuil
        $seuilSortie = (int) Setting::getValue('sortie_stock_max', 20);
        if ($request->quantity > $seuilSortie && !$request->user()->hasRole('proprietaire')) {
            return $this->error(
                "Sortie de {$request->quantity} unités dépasse le seuil autorisé ({$seuilSortie}). Validation du propriétaire requise.",
                403
            );
        }

        $exit = DB::transaction(function () use ($request, $product) {
            $exit = StockExit::create([
                'product_id'  => $request->product_id,
                'created_by'  => $request->user()->id,
                'quantity'    => $request->quantity,
                'reason'      => $request->reason,
                'notes'       => $request->notes,
            ]);

            $product->stock->decrement('quantity', $request->quantity);

            return $exit;
        });

        activity_log($request->user()->id, 'sortie_stock', 'StockExit', $exit->id, [
            'product_id' => $request->product_id,
            'quantity'   => $request->quantity,
            'reason'     => $request->reason,
        ]);

        $nouveauStock = $product->stock->fresh()->quantity;

        return $this->success([
            'exit'         => $this->formatExit($exit->load('product')),
            'nouveau_stock' => $nouveauStock,
            'statut_stock' => $product->stockStatus(),
        ], "Sortie enregistrée. Nouveau stock : {$nouveauStock} {$product->unit}(s).", 201);
    }

    public function indexExits(Request $request): JsonResponse
    {
        $exits = StockExit::with(['product:id,name,unit', 'createdBy:id,name'])
            ->when($request->product_id, fn($q) => $q->where('product_id', $request->product_id))
            ->when($request->reason, fn($q) => $q->where('reason', $request->reason))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn($e) => $this->formatExit($e));

        return $this->success($exits);
    }

    // ── Formatters ────────────────────────────────────────────────────────────

    private function formatEntry(StockEntry $e): array
    {
        return [
            'id'             => $e->id,
            'product'        => $e->product?->name,
            'unit'           => $e->product?->unit,
            'quantity'       => $e->quantity,
            'purchase_price' => $e->purchase_price,
            'supplier'       => $e->supplier,
            'invoice_number' => $e->invoice_number,
            'expiry_date'    => $e->expiry_date,
            'received_by'    => $e->receivedBy?->name,
            'date'           => $e->created_at->format('d/m/Y H:i'),
        ];
    }

    private function formatExit(StockExit $e): array
    {
        return [
            'id'         => $e->id,
            'product'    => $e->product?->name,
            'unit'       => $e->product?->unit,
            'quantity'   => $e->quantity,
            'reason'     => $e->reason,
            'notes'      => $e->notes,
            'created_by' => $e->createdBy?->name,
            'date'       => $e->created_at->format('d/m/Y H:i'),
        ];
    }

    private function success(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null], $status);
    }
}
