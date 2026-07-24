<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('status', ['en_attente', 'validee', 'annulee'])
                ->default('validee')
                ->after('vendor_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cashier_id')->nullable()->change();
            $table->enum('payment_method', ['especes', 'mobile_money'])->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cashier_id')->nullable(false)->change();
            $table->enum('payment_method', ['especes', 'mobile_money'])->default('especes')->nullable(false)->change();
            $table->dropColumn('status');
        });
    }
};
