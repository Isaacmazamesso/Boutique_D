<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1',
            'sale_type'              => 'required|in:detail,gros',
            'payment_method'         => 'required|in:especes,mobile_money',
            'amount_paid'            => 'required|integer|min:0',
            'mobile_money_number'    => 'required_if:payment_method,mobile_money|nullable|string',
            'discount_type'          => 'nullable|in:percent,fixed',
            'discount_value'         => 'nullable|integer|min:0',
            'vendor_id'              => 'nullable|exists:users,id',
            'notes'                  => 'nullable|string',
            'uuid'                   => 'nullable|uuid',
            'customer_id'            => 'nullable|exists:customers,id',
        ]);

        // Déduplication (synchro offline) : un rejeu du même UUID renvoie la vente déjà créée.
        if ($request->uuid) {
            $existing = Sale::where('sync_uuid', $request->uuid)
                ->where('cashier_id', $request->user()->id)
                ->first();
            if ($existing) {
                return $this->success(
                    $this->formatSale($existing->load(['items.product', 'cashier:id,name']), collect()),
                    'Vente déjà enregistrée.',
                    200
                );
            }
        }

        // Session de caisse — requise pour les caissiers, optionnelle pour les autres
        $session = CashSession::where('cashier_id', $request->user()->id)
            ->whereNull('closed_at')
            ->first();

        if (!$session && $request->user()->hasRole('caissier')) {
            return $this->error('Vous devez ouvrir une session de caisse avant de vendre.', 422);
        }

        // Charger les produits avec prix et stock
        $productIds = collect($request->items)->pluck('product_id');
        $products   = Product::with(['price', 'stock'])->whereIn('id', $productIds)->get()->keyBy('id');

        // Vérifications stock + calcul du sous-total
        $subtotal = 0;
        $lineItems = [];
        $priceWarnings = [];

        foreach ($request->items as $item) {
            $product = $products->get($item['product_id']);

            if (!$product || !$product->is_active) {
                return $this->error("Produit ID {$item['product_id']} indisponible.", 422);
            }

            $stockQty = $product->stock?->quantity ?? 0;
            if ($stockQty < $item['quantity']) {
                return $this->error(
                    "Stock insuffisant pour \"{$product->name}\" : {$stockQty} disponible(s), {$item['quantity']} demandé(s).",
                    422
                );
            }

            [$unitPrice, $warning] = $this->resolveUnitPrice($product, $request->sale_type, $item['quantity']);
            if ($warning) $priceWarnings[] = $warning;

            $lineTotal  = $unitPrice * $item['quantity'];
            $subtotal  += $lineTotal;

            $lineItems[] = [
                'product'    => $product,
                'quantity'   => $item['quantity'],
                'unit_price' => $unitPrice,
                'total'      => $lineTotal,
            ];
        }

        // Calcul de la remise
        $discountAmount = 0;
        if ($request->discount_type && $request->discount_value > 0) {
            $discountAmount = $request->discount_type === 'percent'
                ? (int) round($subtotal * $request->discount_value / 100)
                : $request->discount_value;

            // Vérifier l'autorisation si remise > seuil
            $seuilPct = (int) Setting::getValue('remise_max_sans_auth', 10);
            $discountPct = ($subtotal > 0) ? ($discountAmount / $subtotal * 100) : 0;

            if ($discountPct > $seuilPct && !$request->user()->hasRole('proprietaire')) {
                return $this->error(
                    "Remise de " . round($discountPct, 1) . "% dépasse le seuil autorisé ({$seuilPct}%). Autorisation du propriétaire requise.",
                    403
                );
            }
        }

        $total      = max(0, $subtotal - $discountAmount);
        $changeDue  = max(0, $request->amount_paid - $total);

        if ($request->payment_method === 'especes' && $request->amount_paid < $total) {
            return $this->error("Montant reçu ({$request->amount_paid} FCFA) insuffisant. Total dû : {$total} FCFA.", 422);
        }

        // Tout valider en transaction
        $sale = DB::transaction(function () use ($request, $session, $lineItems, $subtotal, $discountAmount, $total, $changeDue) {
            $sale = Sale::create([
                'receipt_number'   => $this->generateReceiptNumber(),
                'sync_uuid'        => $request->uuid,
                'cashier_id'       => $request->user()->id,
                'vendor_id'        => $request->vendor_id,
                'customer_id'      => $request->customer_id,
                'cash_session_id'  => $session?->id,
                'sale_type'        => $request->sale_type,
                'payment_method'   => $request->payment_method,
                'mobile_money_number' => $request->mobile_money_number,
                'subtotal'         => $subtotal,
                'discount_type'    => $request->discount_type,
                'discount_value'   => $request->discount_value ?? 0,
                'total'            => $total,
                'amount_paid'      => $request->amount_paid,
                'change_given'     => $changeDue,
                'notes'            => $request->notes,
            ]);

            foreach ($lineItems as $line) {
                SaleItem::create([
                    'sale_id'    => $sale->id,
                    'product_id' => $line['product']->id,
                    'quantity'   => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total'      => $line['total'],
                ]);

                // Déduire le stock
                $line['product']->stock->decrement('quantity', $line['quantity']);
            }

            return $sale;
        });

        activity_log($request->user()->id, 'vente', 'Sale', $sale->id, [
            'total'          => $total,
            'receipt_number' => $sale->receipt_number,
        ]);

        return $this->success(
            [...$this->formatSale($sale->load(['items.product', 'cashier:id,name', 'customer:id,name']), collect()), 'price_warnings' => $priceWarnings],
            'Vente enregistrée.',
            201
        );
    }

    public function storePending(Request $request): JsonResponse
    {
        $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1',
            'sale_type'              => 'required|in:detail,gros',
            'discount_type'          => 'nullable|in:percent,fixed',
            'discount_value'         => 'nullable|integer|min:0',
            'notes'                  => 'nullable|string',
        ]);

        // Charger les produits avec prix et stock
        $productIds = collect($request->items)->pluck('product_id');
        $products   = Product::with(['price', 'stock'])->whereIn('id', $productIds)->get()->keyBy('id');

        $subtotal  = 0;
        $lineItems = [];
        $priceWarnings = [];

        foreach ($request->items as $item) {
            $product = $products->get($item['product_id']);

            if (!$product || !$product->is_active) {
                return $this->error("Produit ID {$item['product_id']} indisponible.", 422);
            }

            $stockQty = $product->stock?->quantity ?? 0;
            if ($stockQty < $item['quantity']) {
                return $this->error(
                    "Stock insuffisant pour \"{$product->name}\" : {$stockQty} disponible(s), {$item['quantity']} demandé(s).",
                    422
                );
            }

            [$unitPrice, $warning] = $this->resolveUnitPrice($product, $request->sale_type, $item['quantity']);
            if ($warning) $priceWarnings[] = $warning;

            $lineTotal  = $unitPrice * $item['quantity'];
            $subtotal  += $lineTotal;

            $lineItems[] = [
                'product'    => $product,
                'quantity'   => $item['quantity'],
                'unit_price' => $unitPrice,
                'total'      => $lineTotal,
            ];
        }

        $discountAmount = 0;
        if ($request->discount_type && $request->discount_value > 0) {
            $discountAmount = $request->discount_type === 'percent'
                ? (int) round($subtotal * $request->discount_value / 100)
                : $request->discount_value;

            $seuilPct = (int) Setting::getValue('remise_max_sans_auth', 10);
            $discountPct = ($subtotal > 0) ? ($discountAmount / $subtotal * 100) : 0;

            if ($discountPct > $seuilPct && !$request->user()->hasRole('proprietaire')) {
                return $this->error(
                    "Remise de " . round($discountPct, 1) . "% dépasse le seuil autorisé ({$seuilPct}%). Autorisation du propriétaire requise.",
                    403
                );
            }
        }

        $total = max(0, $subtotal - $discountAmount);

        $sale = DB::transaction(function () use ($request, $lineItems, $subtotal, $discountAmount, $total) {
            $sale = Sale::create([
                'receipt_number'  => $this->generateReceiptNumber(),
                'cashier_id'      => null,
                'vendor_id'       => $request->user()->id,
                'cash_session_id' => null,
                'status'          => 'en_attente',
                'sale_type'       => $request->sale_type,
                'payment_method'  => null,
                'subtotal'        => $subtotal,
                'discount_type'   => $request->discount_type,
                'discount_value'  => $request->discount_value ?? 0,
                'total'           => $total,
                'amount_paid'     => 0,
                'change_given'    => 0,
                'notes'           => $request->notes,
            ]);

            foreach ($lineItems as $line) {
                SaleItem::create([
                    'sale_id'    => $sale->id,
                    'product_id' => $line['product']->id,
                    'quantity'   => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total'      => $line['total'],
                ]);

                $line['product']->stock->decrement('quantity', $line['quantity']);
            }

            return $sale;
        });

        activity_log($request->user()->id, 'vente_en_attente', 'Sale', $sale->id, [
            'total'          => $total,
            'receipt_number' => $sale->receipt_number,
        ]);

        return $this->success(
            [...$this->formatSale($sale->load(['items.product', 'vendor:id,name'])), 'price_warnings' => $priceWarnings],
            'Panier envoyé à la caisse.',
            201
        );
    }

    public function pending(): JsonResponse
    {
        $sales = Sale::where('status', 'en_attente')
            ->with(['items.product', 'vendor:id,name'])
            ->oldest()
            ->get();

        return $this->success($sales->map(fn($s) => $this->formatSale($s)));
    }

    public function validatePending(Request $request, Sale $sale): JsonResponse
    {
        if ($sale->status !== 'en_attente') {
            return $this->error('Cette vente n\'est plus en attente.', 422);
        }

        $request->validate([
            'items'                  => 'nullable|array|min:1',
            'items.*.product_id'     => 'required_with:items|exists:products,id',
            'items.*.quantity'       => 'required_with:items|integer|min:1',
            'sale_type'              => 'nullable|in:detail,gros',
            'payment_method'         => 'required|in:especes,mobile_money',
            'amount_paid'            => 'required|integer|min:0',
            'mobile_money_number'    => 'required_if:payment_method,mobile_money|nullable|string',
            'discount_type'          => 'nullable|in:percent,fixed',
            'discount_value'         => 'nullable|integer|min:0',
            'notes'                  => 'nullable|string',
            'customer_id'            => 'nullable|exists:customers,id',
        ]);

        $session = CashSession::where('cashier_id', $request->user()->id)
            ->whereNull('closed_at')
            ->first();

        if (!$session && $request->user()->hasRole('caissier')) {
            return $this->error('Vous devez ouvrir une session de caisse avant d\'encaisser.', 422);
        }

        $saleType = $request->sale_type ?? $sale->sale_type;

        // Aucune mutation avant ce point : toute la validation se fait en lecture seule.
        // Si les articles sont modifiés, la quantité déjà réservée par CE panier pour un
        // produit compte comme "disponible" pour la vérification (elle sera réellement
        // restituée puis re-décomptée à l'intérieur de la transaction plus bas, jamais avant).
        if ($request->has('items')) {
            $sale->load('items.product');
            $oldQuantitiesByProduct = $sale->items->groupBy('product_id')->map(fn($items) => $items->sum('quantity'));

            $productIds = collect($request->items)->pluck('product_id');
            $products   = Product::with(['price', 'stock'])->whereIn('id', $productIds)->get()->keyBy('id');

            $subtotal  = 0;
            $lineItems = [];
            $priceWarnings = [];

            foreach ($request->items as $item) {
                $product = $products->get($item['product_id']);

                if (!$product || !$product->is_active) {
                    return $this->error("Produit ID {$item['product_id']} indisponible.", 422);
                }

                $reserved = $oldQuantitiesByProduct[$product->id] ?? 0;
                $stockQty = ($product->stock?->quantity ?? 0) + $reserved;
                if ($stockQty < $item['quantity']) {
                    return $this->error(
                        "Stock insuffisant pour \"{$product->name}\" : {$stockQty} disponible(s), {$item['quantity']} demandé(s).",
                        422
                    );
                }

                [$unitPrice, $warning] = $this->resolveUnitPrice($product, $saleType, $item['quantity']);
                if ($warning) $priceWarnings[] = $warning;

                $lineTotal  = $unitPrice * $item['quantity'];
                $subtotal  += $lineTotal;

                $lineItems[] = [
                    'product'    => $product,
                    'quantity'   => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'total'      => $lineTotal,
                ];
            }
        } else {
            $subtotal  = $sale->subtotal;
            $lineItems = null; // aucun changement d'articles
            $priceWarnings = [];
        }

        $discountType  = $request->discount_type ?? $sale->discount_type;
        $discountValue = $request->has('discount_type') ? ($request->discount_value ?? 0) : $sale->discount_value;

        $discountAmount = 0;
        if ($discountType && $discountValue > 0) {
            $discountAmount = $discountType === 'percent'
                ? (int) round($subtotal * $discountValue / 100)
                : $discountValue;

            $seuilPct = (int) Setting::getValue('remise_max_sans_auth', 10);
            $discountPct = ($subtotal > 0) ? ($discountAmount / $subtotal * 100) : 0;

            if ($discountPct > $seuilPct && !$request->user()->hasRole('proprietaire')) {
                return $this->error(
                    "Remise de " . round($discountPct, 1) . "% dépasse le seuil autorisé ({$seuilPct}%). Autorisation du propriétaire requise.",
                    403
                );
            }
        }

        $total     = max(0, $subtotal - $discountAmount);
        $changeDue = max(0, $request->amount_paid - $total);

        if ($request->payment_method === 'especes' && $request->amount_paid < $total) {
            return $this->error("Montant reçu ({$request->amount_paid} FCFA) insuffisant. Total dû : {$total} FCFA.", 422);
        }

        // À partir d'ici, toutes les mutations (restitution, remplacement des articles,
        // décompte, mise à jour de la vente) sont atomiques : soit tout s'applique, soit rien.
        $alreadyValidated = false;

        DB::transaction(function () use ($request, $sale, $session, $saleType, $lineItems, $subtotal, $discountType, $discountValue, $total, $changeDue, &$alreadyValidated) {
            $locked = Sale::whereKey($sale->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'en_attente') {
                $alreadyValidated = true;
                return;
            }

            if ($lineItems !== null) {
                foreach ($sale->items as $oldItem) {
                    $oldItem->product?->stock?->increment('quantity', $oldItem->quantity);
                }

                $sale->items()->delete();
                foreach ($lineItems as $line) {
                    SaleItem::create([
                        'sale_id'    => $sale->id,
                        'product_id' => $line['product']->id,
                        'quantity'   => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'total'      => $line['total'],
                    ]);
                    $line['product']->stock->decrement('quantity', $line['quantity']);
                }
            }

            $sale->update([
                'cashier_id'          => $request->user()->id,
                'cash_session_id'     => $session?->id,
                'status'              => 'validee',
                'sale_type'           => $saleType,
                'payment_method'      => $request->payment_method,
                'mobile_money_number' => $request->mobile_money_number,
                'subtotal'            => $subtotal,
                'discount_type'       => $discountType,
                'discount_value'      => $discountValue ?? 0,
                'total'               => $total,
                'amount_paid'         => $request->amount_paid,
                'change_given'        => $changeDue,
                'notes'               => $request->notes ?? $sale->notes,
                'customer_id'         => $request->customer_id ?? $sale->customer_id,
            ]);
        });

        if ($alreadyValidated) {
            return $this->error('Cette vente n\'est plus en attente.', 422);
        }

        activity_log($request->user()->id, 'validation_vente_attente', 'Sale', $sale->id, [
            'total' => $total,
        ]);

        return $this->success(
            [...$this->formatSale($sale->fresh()->load(['items.product', 'cashier:id,name', 'vendor:id,name', 'customer:id,name'])), 'price_warnings' => $priceWarnings],
            'Vente validée.'
        );
    }

    public function cancelPending(Request $request, Sale $sale): JsonResponse
    {
        if ($sale->status !== 'en_attente') {
            return $this->error('Cette vente n\'est plus en attente.', 422);
        }

        if ($sale->vendor_id !== $request->user()->id && !$request->user()->hasAnyRole(['caissier', 'gestionnaire', 'proprietaire'])) {
            return $this->error('Vous n\'êtes pas autorisé à annuler ce panier.', 403);
        }

        $sale->load('items.product.stock');

        DB::transaction(function () use ($sale) {
            foreach ($sale->items as $item) {
                $item->product?->stock?->increment('quantity', $item->quantity);
            }
            $sale->update(['status' => 'annulee']);
        });

        activity_log($request->user()->id, 'annulation_vente_attente', 'Sale', $sale->id, [
            'receipt_number' => $sale->receipt_number,
        ]);

        return $this->success(null, 'Panier annulé, stock restitué.');
    }

    public function index(Request $request): JsonResponse
    {
        $query = Sale::with(['cashier:id,name', 'items.product'])
            ->when(!$request->user()->hasRole(['proprietaire', 'gestionnaire']), fn($q) =>
                $q->where('cashier_id', $request->user()->id)
            )
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->when($request->sale_type, fn($q) => $q->where('sale_type', $request->sale_type))
            ->when($request->payment_method, fn($q) => $q->where('payment_method', $request->payment_method))
            ->latest();

        $sales = $query->limit(100)->get();

        $refundedBySale = RefundItem::join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->whereIn('refunds.sale_id', $sales->pluck('id'))
            ->selectRaw('refunds.sale_id, refund_items.product_id, SUM(refund_items.quantity) as qty')
            ->groupBy('refunds.sale_id', 'refund_items.product_id')
            ->get()
            ->groupBy('sale_id');

        $sales = $sales->map(fn($s) => $this->formatSale($s, ($refundedBySale[$s->id] ?? collect())->pluck('qty', 'product_id')));

        return $this->success($sales);
    }

    public function show(Sale $sale): JsonResponse
    {
        return $this->success(
            $this->formatSale($sale->load(['items.product', 'cashier:id,name', 'vendor:id,name', 'refunds']))
        );
    }

    public function findByReceipt(Request $request): JsonResponse
    {
        $request->validate(['receipt_number' => 'required|string']);

        $sale = Sale::with(['items.product', 'cashier:id,name', 'refunds'])
            ->where('receipt_number', $request->receipt_number)
            ->first();

        if (!$sale) {
            return $this->error('Reçu introuvable.', 404);
        }

        return $this->success($this->formatSale($sale));
    }

    public function refund(Request $request, Sale $sale): JsonResponse
    {
        $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.sale_item_id' => 'required|exists:sale_items,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'reason'             => 'required|string|max:255',
        ]);

        // Montant remboursement
        $saleItemsById = $sale->items->keyBy('id');
        $refundAmount  = 0;
        $refundLines   = [];

        foreach ($request->items as $item) {
            $saleItem = $saleItemsById->get($item['sale_item_id']);

            if (!$saleItem) {
                return $this->error("Article ID {$item['sale_item_id']} n'appartient pas à cette vente.", 422);
            }

            $alreadyRefunded = RefundItem::whereHas('refund', fn($q) => $q->where('sale_id', $sale->id))
                ->where('product_id', $saleItem->product_id)
                ->sum('quantity');

            $maxRefundable = $saleItem->quantity - $alreadyRefunded;

            if ($item['quantity'] > $maxRefundable) {
                return $this->error(
                    "Quantité remboursable max pour \"{$saleItem->product->name}\" : {$maxRefundable}.",
                    422
                );
            }

            $lineAmount    = $saleItem->unit_price * $item['quantity'];
            $refundAmount += $lineAmount;

            $refundLines[] = [
                'product_id' => $saleItem->product_id,
                'quantity'   => $item['quantity'],
                'amount'     => $lineAmount,
            ];
        }

        // Vérifier l'autorisation si montant > seuil
        $seuilRemb = (int) Setting::getValue('remboursement_max', 50000);
        if ($refundAmount > $seuilRemb && !$request->user()->hasRole('proprietaire')) {
            return $this->error(
                "Remboursement de {$refundAmount} FCFA dépasse le seuil autorisé ({$seuilRemb} FCFA). Autorisation du propriétaire requise.",
                403
            );
        }

        $refund = DB::transaction(function () use ($request, $sale, $refundAmount, $refundLines) {
            $refund = Refund::create([
                'sale_id'    => $sale->id,
                'cashier_id' => $request->user()->id,
                'amount'     => $refundAmount,
                'reason'     => $request->reason,
            ]);

            foreach ($refundLines as $line) {
                RefundItem::create([
                    'refund_id'  => $refund->id,
                    'product_id' => $line['product_id'],
                    'quantity'   => $line['quantity'],
                    'amount'     => $line['amount'],
                ]);

                // Réintégrer le stock
                Product::find($line['product_id'])->stock->increment('quantity', $line['quantity']);
            }

            return $refund;
        });

        activity_log($request->user()->id, 'remboursement', 'Refund', $refund->id, [
            'sale_receipt' => $sale->receipt_number,
            'amount'       => $refundAmount,
        ]);

        return $this->success([
            'refund_id'     => $refund->id,
            'amount'        => $refundAmount,
            'sale_receipt'  => $sale->receipt_number,
        ], "Remboursement de {$refundAmount} FCFA effectué. Stock réintégré.");
    }

    public function receiptPdf(Sale $sale)
    {
        $sale->load(['items.product', 'cashier:id,name', 'vendor:id,name']);

        return Pdf::loadView('receipts.sale', ['sale' => $this->formatSale($sale)])
            ->setPaper([0, 0, 226.77, 841.89]) // 80 mm de large
            ->stream('recu-' . $sale->receipt_number . '.pdf');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generateReceiptNumber(): string
    {
        $date  = now()->format('Ymd');
        $count = Sale::whereDate('created_at', today())->count() + 1;
        return 'VTE-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Détermine le prix unitaire d'une ligne. En vente gros, si la quantité n'atteint pas
     * le seuil gros du produit, on bascule automatiquement au prix détail (pas de blocage)
     * et on remonte un message d'alerte pour information du caissier.
     */
    private function resolveUnitPrice(Product $product, string $saleType, int $quantity): array
    {
        $price = $product->price;

        if ($saleType === 'gros') {
            if ($quantity >= $price->wholesale_min_qty) {
                return [$price->wholesale_price, null];
            }

            return [
                $price->retail_price,
                "\"{$product->name}\" : quantité ({$quantity}) sous le seuil gros ({$price->wholesale_min_qty}) — prix détail appliqué.",
            ];
        }

        return [$price->retail_price, null];
    }

    private function formatSale(Sale $sale, ?\Illuminate\Support\Collection $refundedByProduct = null): array
    {
        $refundedByProduct ??= RefundItem::whereHas('refund', fn($q) => $q->where('sale_id', $sale->id))
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        return [
            'id'             => $sale->id,
            'receipt_number' => $sale->receipt_number,
            'status'         => $sale->status,
            'sale_type'      => $sale->sale_type,
            'payment_method' => $sale->payment_method,
            'cashier'        => $sale->cashier?->name,
            'vendor'         => $sale->vendor?->name,
            'customer'       => $sale->customer?->name,
            'subtotal'       => $sale->subtotal,
            'discount_type'  => $sale->discount_type,
            'discount_value' => $sale->discount_value,
            'total'          => $sale->total,
            'amount_paid'    => $sale->amount_paid,
            'change_given'   => $sale->change_given,
            'notes'          => $sale->notes,
            'date'           => $sale->created_at->format('d/m/Y H:i'),
            'items'          => $sale->relationLoaded('items')
                ? $sale->items->map(fn($i) => [
                    'id'                => $i->id,
                    'product_id'        => $i->product_id,
                    'product'           => $i->product?->name,
                    'unit'              => $i->product?->unit,
                    'quantity'          => $i->quantity,
                    'unit_price'        => $i->unit_price,
                    'total'             => $i->total,
                    'refunded_quantity' => (int) ($refundedByProduct[$i->product_id] ?? 0),
                ])
                : [],
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
