<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catégories de produits
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('color', 7)->nullable();
            $table->string('icon')->nullable();
            $table->timestamps();
        });

        // Produits
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('unit');
            $table->string('barcode')->nullable()->unique();
            $table->string('photo')->nullable();
            $table->string('brand')->nullable();
            $table->string('supplier')->nullable();
            $table->date('expiry_date')->nullable();
            $table->integer('min_stock_alert')->default(5);
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Prix des produits (gros / détail)
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('purchase_price')->default(0);
            $table->integer('retail_price')->default(0);
            $table->integer('wholesale_price')->default(0);
            $table->integer('wholesale_min_qty')->default(1);
            $table->timestamps();
        });

        // Historique des modifications de prix
        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users');
            $table->integer('old_purchase_price');
            $table->integer('new_purchase_price');
            $table->integer('old_retail_price');
            $table->integer('new_retail_price');
            $table->integer('old_wholesale_price');
            $table->integer('new_wholesale_price');
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        // Stock courant (une ligne par produit)
        Schema::create('stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();
        });

        // Entrées de stock (réceptions marchandise)
        Schema::create('stock_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('received_by')->constrained('users');
            $table->integer('quantity');
            $table->integer('purchase_price');
            $table->string('supplier')->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Sorties manuelles de stock (perte, casse, etc.)
        Schema::create('stock_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->integer('quantity');
            $table->enum('reason', ['casse', 'peremption', 'usage_interne', 'perte', 'vol', 'autre']);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Sessions de caisse
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_id')->constrained('users');
            $table->integer('opening_amount')->default(0);
            $table->integer('closing_amount')->nullable();
            $table->integer('theoretical_amount')->nullable();
            $table->integer('difference')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        // Ventes
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->foreignId('cashier_id')->constrained('users');
            $table->foreignId('vendor_id')->nullable()->constrained('users');
            $table->foreignId('cash_session_id')->nullable()->constrained('cash_sessions');
            $table->enum('sale_type', ['detail', 'gros'])->default('detail');
            $table->enum('payment_method', ['especes', 'mobile_money'])->default('especes');
            $table->string('mobile_money_number')->nullable();
            $table->integer('subtotal');
            $table->enum('discount_type', ['percent', 'fixed'])->nullable();
            $table->integer('discount_value')->default(0);
            $table->integer('total');
            $table->integer('amount_paid')->default(0);
            $table->integer('change_given')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Lignes de vente
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->integer('quantity');
            $table->integer('unit_price');
            $table->integer('total');
            $table->timestamps();
        });

        // Remboursements
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained();
            $table->foreignId('cashier_id')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->integer('amount');
            $table->string('reason');
            $table->timestamps();
        });

        // Lignes de remboursement
        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->integer('quantity');
            $table->integer('amount');
            $table->timestamps();
        });

        // Inventaires physiques
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['complet', 'partiel'])->default('complet');
            $table->foreignId('category_id')->nullable()->constrained();
            $table->enum('status', ['en_cours', 'valide'])->default('en_cours');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('validated_by')->nullable()->constrained('users');
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });

        // Lignes d'inventaire
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->integer('theoretical_qty');
            $table->integer('counted_qty')->nullable();
            $table->integer('difference')->nullable();
            $table->timestamps();
        });

        // Journal d'activité
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('action');
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('device')->nullable();
            $table->timestamps();
        });

        // Paramètres système
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('stock_exits');
        Schema::dropIfExists('stock_entries');
        Schema::dropIfExists('stock');
        Schema::dropIfExists('price_history');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
