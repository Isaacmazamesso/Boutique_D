<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::withCount(['products', 'activeProducts'])
            ->orderBy('name')
            ->get()
            ->map(fn($c) => $this->formatCategory($c));

        return $this->success($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:categories',
            'description' => 'nullable|string|max:255',
            'color'       => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'icon'        => 'nullable|string|max:50',
        ]);

        $category = Category::create($request->only(['name', 'description', 'color', 'icon']));

        activity_log($request->user()->id, 'creation_categorie', 'Category', $category->id, ['name' => $category->name]);

        return $this->success($this->formatCategory($category), 'Catégorie créée.', 201);
    }

    public function show(Category $category): JsonResponse
    {
        return $this->success($this->formatCategory($category->loadCount(['products', 'activeProducts'])));
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $request->validate([
            'name'        => 'sometimes|string|max:100|unique:categories,name,' . $category->id,
            'description' => 'nullable|string|max:255',
            'color'       => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'icon'        => 'nullable|string|max:50',
        ]);

        $category->update($request->only(['name', 'description', 'color', 'icon']));

        activity_log($request->user()->id, 'modification_categorie', 'Category', $category->id);

        return $this->success($this->formatCategory($category), 'Catégorie mise à jour.');
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        if ($category->activeProducts()->exists()) {
            return $this->error('Impossible : cette catégorie contient des produits actifs.', 422);
        }

        activity_log($request->user()->id, 'suppression_categorie', 'Category', $category->id, ['name' => $category->name]);
        $category->delete();

        return $this->success(null, 'Catégorie supprimée.');
    }

    private function formatCategory(Category $category): array
    {
        return [
            'id'                   => $category->id,
            'name'                 => $category->name,
            'description'          => $category->description,
            'color'                => $category->color,
            'icon'                 => $category->icon,
            'products_count'       => $category->products_count ?? 0,
            'active_products_count'=> $category->active_products_count ?? 0,
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
