<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'price', 'stock'])
            ->when($request->search, fn($q) =>
                $q->where('name', 'ilike', '%' . $request->search . '%')
            )
            ->when($request->category_id, fn($q) =>
                $q->where('category_id', $request->category_id)
            )
            ->when($request->status === 'actif', fn($q) =>
                $q->where('is_active', true)
            )
            ->when($request->status === 'inactif', fn($q) =>
                $q->where('is_active', false)
            )
            ->when($request->stock_status === 'rupture', fn($q) =>
                $q->whereHas('stock', fn($s) => $s->where('quantity', '<=', 0))
            )
            ->when($request->stock_status === 'bas', fn($q) =>
                $q->whereHas('stock', fn($s) =>
                    $s->whereRaw('quantity > 0 AND quantity <= products.min_stock_alert')
                )
            )
            ->orderBy('name');

        $products = $query->get()->map(fn($p) => $this->formatProduct($p));

        return $this->success($products);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'               => 'required|string|max:150',
            'category_id'        => 'required|exists:categories,id',
            'unit'               => 'required|string|max:30',
            'barcode'            => 'nullable|string|unique:products',
            'brand'              => 'nullable|string|max:100',
            'supplier'           => 'nullable|string|max:100',
            'expiry_date'        => 'nullable|date',
            'min_stock_alert'    => 'nullable|integer|min:0',
            'location'           => 'nullable|string|max:100',
            // Prix
            'purchase_price'     => 'required|integer|min:0',
            'retail_price'       => 'required|integer|min:0',
            'wholesale_price'    => 'required|integer|min:0',
            'wholesale_min_qty'  => 'nullable|integer|min:1',
        ]);

        $this->validatePrices($request);

        $product = Product::create([
            'category_id'     => $request->category_id,
            'name'            => $request->name,
            'unit'            => $request->unit,
            'barcode'         => $request->barcode,
            'brand'           => $request->brand,
            'supplier'        => $request->supplier,
            'expiry_date'     => $request->expiry_date,
            'min_stock_alert' => $request->min_stock_alert ?? 5,
            'location'        => $request->location,
            'is_active'       => true,
        ]);

        // Créer le prix
        ProductPrice::create([
            'product_id'        => $product->id,
            'purchase_price'    => $request->purchase_price,
            'retail_price'      => $request->retail_price,
            'wholesale_price'   => $request->wholesale_price,
            'wholesale_min_qty' => $request->wholesale_min_qty ?? 1,
        ]);

        // Créer le stock à 0
        Stock::create(['product_id' => $product->id, 'quantity' => 0]);

        activity_log($request->user()->id, 'creation_produit', 'Product', $product->id, ['name' => $product->name]);

        return $this->success(
            $this->formatProduct($product->load(['category', 'price', 'stock'])),
            'Produit créé.',
            201
        );
    }

    public function show(Product $product): JsonResponse
    {
        return $this->success(
            $this->formatProduct($product->load(['category', 'price', 'stock']))
        );
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'name'              => 'sometimes|string|max:150',
            'category_id'       => 'sometimes|exists:categories,id',
            'unit'              => 'sometimes|string|max:30',
            'barcode'           => 'nullable|string|unique:products,barcode,' . $product->id,
            'brand'             => 'nullable|string|max:100',
            'supplier'          => 'nullable|string|max:100',
            'expiry_date'       => 'nullable|date',
            'min_stock_alert'   => 'nullable|integer|min:0',
            'location'          => 'nullable|string|max:100',
            // Prix (optionnels à la mise à jour)
            'purchase_price'    => 'sometimes|integer|min:0',
            'retail_price'      => 'sometimes|integer|min:0',
            'wholesale_price'   => 'sometimes|integer|min:0',
            'wholesale_min_qty' => 'nullable|integer|min:1',
        ]);

        $product->update($request->only([
            'name', 'category_id', 'unit', 'barcode',
            'brand', 'supplier', 'expiry_date', 'min_stock_alert', 'location',
        ]));

        // Mise à jour des prix si fournis
        if ($request->hasAny(['purchase_price', 'retail_price', 'wholesale_price', 'wholesale_min_qty'])) {
            $this->updatePrices($request, $product);
        }

        activity_log($request->user()->id, 'modification_produit', 'Product', $product->id);

        return $this->success(
            $this->formatProduct($product->load(['category', 'price', 'stock'])),
            'Produit mis à jour.'
        );
    }

    public function toggleStatus(Request $request, Product $product): JsonResponse
    {
        $product->update(['is_active' => !$product->is_active]);

        $msg = $product->is_active ? 'Produit activé.' : 'Produit désactivé.';
        activity_log($request->user()->id, 'toggle_produit', 'Product', $product->id);

        return $this->success(['is_active' => $product->is_active], $msg);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        if ($product->hasSales()) {
            return $this->error('Impossible : ce produit a des ventes enregistrées. Désactivez-le plutôt.', 422);
        }

        activity_log($request->user()->id, 'suppression_produit', 'Product', $product->id, ['name' => $product->name]);
        $product->delete();

        return $this->success(null, 'Produit supprimé.');
    }

    public function findByBarcode(Request $request): JsonResponse
    {
        $request->validate(['barcode' => 'required|string']);

        $product = Product::with(['category', 'price', 'stock'])
            ->where('barcode', $request->barcode)
            ->where('is_active', true)
            ->first();

        if (!$product) {
            return $this->error('Produit introuvable pour ce code-barres.', 404);
        }

        return $this->success($this->formatProduct($product));
    }

    public function priceHistory(Product $product): JsonResponse
    {
        $history = $product->priceHistory()
            ->with('changedBy:id,name')
            ->latest()
            ->limit(50)
            ->get();

        return $this->success($history);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validatePrices(Request $request): void
    {
        $purchase = $request->purchase_price;
        $retail   = $request->retail_price;
        $wholesale= $request->wholesale_price;

        // Avertissement si prix de vente < prix d'achat (géré côté front)
        // La règle stricte est appliquée uniquement par le propriétaire
    }

    private function updatePrices(Request $request, Product $product): void
    {
        $price = $product->price;
        if (!$price) return;

        // Enregistrer l'historique si changement
        $changed = false;
        foreach (['purchase_price', 'retail_price', 'wholesale_price'] as $field) {
            if ($request->has($field) && $request->$field != $price->$field) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            PriceHistory::create([
                'product_id'          => $product->id,
                'changed_by'          => $request->user()->id,
                'old_purchase_price'  => $price->purchase_price,
                'new_purchase_price'  => $request->purchase_price ?? $price->purchase_price,
                'old_retail_price'    => $price->retail_price,
                'new_retail_price'    => $request->retail_price ?? $price->retail_price,
                'old_wholesale_price' => $price->wholesale_price,
                'new_wholesale_price' => $request->wholesale_price ?? $price->wholesale_price,
                'reason'              => $request->price_reason,
            ]);
        }

        $price->update([
            'purchase_price'    => $request->purchase_price    ?? $price->purchase_price,
            'retail_price'      => $request->retail_price      ?? $price->retail_price,
            'wholesale_price'   => $request->wholesale_price   ?? $price->wholesale_price,
            'wholesale_min_qty' => $request->wholesale_min_qty ?? $price->wholesale_min_qty,
        ]);
    }

    private function formatProduct(Product $product): array
    {
        $qty = $product->stock?->quantity ?? 0;

        return [
            'id'              => $product->id,
            'name'            => $product->name,
            'category_id'     => $product->category_id,
            'category'        => $product->category?->name,
            'unit'            => $product->unit,
            'barcode'         => $product->barcode,
            'photo'           => $product->photo,
            'brand'           => $product->brand,
            'supplier'        => $product->supplier,
            'expiry_date'     => $product->expiry_date?->format('Y-m-d'),
            'min_stock_alert' => $product->min_stock_alert,
            'location'        => $product->location,
            'is_active'       => $product->is_active,
            'stock_quantity'  => $qty,
            'stock_status'    => $product->stockStatus(),
            'prices' => $product->price ? [
                'purchase_price'    => $product->price->purchase_price,
                'retail_price'      => $product->price->retail_price,
                'wholesale_price'   => $product->price->wholesale_price,
                'wholesale_min_qty' => $product->price->wholesale_min_qty,
            ] : null,
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
