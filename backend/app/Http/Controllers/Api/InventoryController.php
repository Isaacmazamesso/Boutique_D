<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index(): JsonResponse
    {
        $inventories = Inventory::with(['createdBy:id,name', 'validatedBy:id,name', 'category:id,name'])
            ->withCount('items')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn($i) => $this->formatInventory($i));

        return $this->success($inventories);
    }

    // Étape 1 — Créer un inventaire et initialiser les lignes
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:100',
            'type'        => 'required|in:complet,partiel',
            'category_id' => 'required_if:type,partiel|nullable|exists:categories,id',
        ]);

        // Vérifier qu'il n'y a pas d'inventaire en cours
        if (Inventory::where('status', 'en_cours')->exists()) {
            return $this->error('Un inventaire est déjà en cours. Terminez-le avant d\'en créer un nouveau.', 422);
        }

        $inventory = DB::transaction(function () use ($request) {
            $inventory = Inventory::create([
                'name'        => $request->name,
                'type'        => $request->type,
                'category_id' => $request->category_id,
                'status'      => 'en_cours',
                'created_by'  => $request->user()->id,
            ]);

            // Charger les produits concernés avec leur stock actuel
            $products = Product::with('stock')
                ->where('is_active', true)
                ->when($request->type === 'partiel', fn($q) =>
                    $q->where('category_id', $request->category_id)
                )
                ->get();

            foreach ($products as $product) {
                InventoryItem::create([
                    'inventory_id'   => $inventory->id,
                    'product_id'     => $product->id,
                    'theoretical_qty'=> $product->stock?->quantity ?? 0,
                    'counted_qty'    => null,
                    'difference'     => null,
                ]);
            }

            return $inventory;
        });

        activity_log($request->user()->id, 'creation_inventaire', 'Inventory', $inventory->id, [
            'type' => $request->type,
            'nb_produits' => $inventory->items()->count(),
        ]);

        return $this->success(
            $this->formatInventory($inventory->load(['items.product:id,name,unit', 'createdBy:id,name'])),
            "Inventaire créé avec {$inventory->items()->count()} produit(s) à compter.",
            201
        );
    }

    public function show(Inventory $inventory): JsonResponse
    {
        $inventory->load(['items.product.category', 'createdBy:id,name', 'validatedBy:id,name']);

        return $this->success($this->formatInventory($inventory, detailed: true));
    }

    // Étape 2 — Saisir les quantités comptées (peut être partiel, rayon par rayon)
    public function count(Request $request, Inventory $inventory): JsonResponse
    {
        if ($inventory->status !== 'en_cours') {
            return $this->error('Cet inventaire est déjà validé.', 422);
        }

        $request->validate([
            'items'                   => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.counted_qty'     => 'required|integer|min:0',
        ]);

        $updated = 0;
        foreach ($request->items as $item) {
            $inventoryItem = InventoryItem::find($item['inventory_item_id']);

            if ($inventoryItem->inventory_id !== $inventory->id) {
                continue;
            }

            $inventoryItem->update([
                'counted_qty' => $item['counted_qty'],
                'difference'  => $item['counted_qty'] - $inventoryItem->theoretical_qty,
            ]);
            $updated++;
        }

        $total    = $inventory->items()->count();
        $counted  = $inventory->items()->whereNotNull('counted_qty')->count();
        $restants = $total - $counted;

        return $this->success([
            'mis_a_jour'   => $updated,
            'total'        => $total,
            'comptes'      => $counted,
            'restants'     => $restants,
            'progression'  => $total > 0 ? round($counted / $total * 100) : 0,
        ], "{$updated} ligne(s) mise(s) à jour. {$restants} produit(s) restant(s) à compter.");
    }

    // Étape 3 — Valider l'inventaire (propriétaire uniquement) → ajuste le stock
    public function validate(Request $request, Inventory $inventory): JsonResponse
    {
        if ($inventory->status !== 'en_cours') {
            return $this->error('Cet inventaire est déjà validé.', 422);
        }

        $nonComptes = $inventory->items()->whereNull('counted_qty')->count();
        if ($nonComptes > 0) {
            return $this->error(
                "{$nonComptes} produit(s) n'ont pas encore été comptés. Complétez le comptage avant de valider.",
                422
            );
        }

        DB::transaction(function () use ($request, $inventory) {
            $inventory->load('items.product.stock');

            foreach ($inventory->items as $item) {
                if ($item->difference !== 0 && $item->product?->stock) {
                    $item->product->stock->update(['quantity' => $item->counted_qty]);
                }
            }

            $inventory->update([
                'status'       => 'valide',
                'validated_by' => $request->user()->id,
                'validated_at' => now(),
            ]);
        });

        activity_log($request->user()->id, 'validation_inventaire', 'Inventory', $inventory->id);

        $inventory->load('items.product');
        $ecarts = $inventory->items->filter(fn($i) => $i->difference != 0);

        return $this->success([
            'inventory'          => $this->formatInventory($inventory),
            'nb_ecarts'          => $ecarts->count(),
            'valeur_ecart_fcfa'  => $inventory->totalValueDifference(),
        ], "Inventaire validé. Stock ajusté. {$ecarts->count()} écart(s) constaté(s).");
    }

    // ── Formatters ────────────────────────────────────────────────────────────

    private function formatInventory(Inventory $inv, bool $detailed = false): array
    {
        $base = [
            'id'           => $inv->id,
            'name'         => $inv->name,
            'type'         => $inv->type,
            'status'       => $inv->status,
            'category'     => $inv->category?->name,
            'created_by'   => $inv->createdBy?->name,
            'validated_by' => $inv->validatedBy?->name,
            'validated_at' => $inv->validated_at?->format('d/m/Y H:i'),
            'created_at'   => $inv->created_at->format('d/m/Y H:i'),
            'items_count'  => $inv->items_count ?? $inv->items->count(),
        ];

        if ($detailed && $inv->relationLoaded('items')) {
            $counted = $inv->items->whereNotNull('counted_qty')->count();
            $base['progression'] = $base['items_count'] > 0
                ? round($counted / $base['items_count'] * 100)
                : 0;

            $base['items'] = $inv->items->map(fn($i) => [
                'id'              => $i->id,
                'product_id'      => $i->product_id,
                'product'         => $i->product?->name,
                'unit'            => $i->product?->unit,
                'category'        => $i->product?->category?->name,
                'theoretical_qty' => $i->theoretical_qty,
                'counted_qty'     => $i->counted_qty,
                'difference'      => $i->difference,
                'status'          => is_null($i->counted_qty) ? 'non_compte'
                    : ($i->difference == 0 ? 'ok' : 'ecart'),
            ])->values();

            if ($inv->status === 'valide') {
                $base['total_ecarts']       = $inv->totalDifference();
                $base['valeur_ecart_fcfa']  = $inv->totalValueDifference();
            }
        }

        return $base;
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
