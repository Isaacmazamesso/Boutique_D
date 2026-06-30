<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashSessionController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Routes publiques
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// Routes protégées (token requis)
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::put('password', [AuthController::class, 'changePassword']);
    });

    // Sessions de caisse
    Route::prefix('cash-sessions')->group(function () {
        Route::get('current', [CashSessionController::class, 'current']);
        Route::get('/', [CashSessionController::class, 'index']);
        Route::post('open', [CashSessionController::class, 'open']);
        Route::post('close', [CashSessionController::class, 'close']);
    });

    // Ventes (POS)
    Route::prefix('sales')->group(function () {
        Route::get('/', [SaleController::class, 'index']);
        Route::post('/', [SaleController::class, 'store']);
        Route::get('receipt', [SaleController::class, 'findByReceipt']);
        Route::get('{sale}', [SaleController::class, 'show']);
        Route::post('{sale}/refund', [SaleController::class, 'refund']);
    });

    // Dashboard — propriétaire uniquement
    Route::middleware('role:proprietaire')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::prefix('reports')->group(function () {
            Route::get('sales', [ReportController::class, 'sales']);
            Route::get('stock', [ReportController::class, 'stock']);
            Route::get('treasury', [ReportController::class, 'treasury']);
            Route::get('employees', [ReportController::class, 'employees']);
        });
    });

    // Stock
    Route::prefix('stock')->group(function () {
        Route::get('dashboard', [StockController::class, 'dashboard']);
        Route::get('alerts', [StockController::class, 'alerts']);
        Route::get('entries', [StockController::class, 'indexEntries']);
        Route::get('exits', [StockController::class, 'indexExits']);
        Route::middleware('role:proprietaire|gestionnaire')->group(function () {
            Route::post('entries', [StockController::class, 'storeEntry']);
            Route::post('exits', [StockController::class, 'storeExit']);
        });
    });

    // Inventaires
    Route::prefix('inventories')->group(function () {
        Route::get('/', [InventoryController::class, 'index']);
        Route::get('{inventory}', [InventoryController::class, 'show']);
        Route::post('{inventory}/count', [InventoryController::class, 'count']);
        Route::middleware('role:proprietaire|gestionnaire')->group(function () {
            Route::post('/', [InventoryController::class, 'store']);
        });
        Route::middleware('role:proprietaire')->group(function () {
            Route::post('{inventory}/validate', [InventoryController::class, 'validate']);
        });
    });

    // Catégories — lecture : tous | écriture : propriétaire
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{category}', [CategoryController::class, 'show']);
    Route::middleware('role:proprietaire')->prefix('categories')->group(function () {
        Route::post('/', [CategoryController::class, 'store']);
        Route::put('{category}', [CategoryController::class, 'update']);
        Route::delete('{category}', [CategoryController::class, 'destroy']);
    });

    // Produits — lecture : tous | écriture : propriétaire
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/barcode', [ProductController::class, 'findByBarcode']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::get('products/{product}/price-history', [ProductController::class, 'priceHistory']);
    Route::middleware('role:proprietaire')->prefix('products')->group(function () {
        Route::post('/', [ProductController::class, 'store']);
        Route::put('{product}', [ProductController::class, 'update']);
        Route::patch('{product}/toggle-status', [ProductController::class, 'toggleStatus']);
        Route::delete('{product}', [ProductController::class, 'destroy']);
    });

    // Gestion des utilisateurs — propriétaire uniquement
    Route::middleware('role:proprietaire')->prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('{user}', [UserController::class, 'show']);
        Route::put('{user}', [UserController::class, 'update']);
        Route::patch('{user}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::post('{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::delete('{user}', [UserController::class, 'destroy']);
        Route::get('{user}/logs', [UserController::class, 'logs']);
    });
});
