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
            // Redéclarer en varchar (et non enum()) évite un bug de Laravel sur PostgreSQL :
            // enum()->change() génère "ALTER COLUMN ... TYPE varchar(255) CHECK (...)" en une
            // seule clause, syntaxe invalide côté PostgreSQL (le CHECK existant, posé par la
            // migration d'origine, reste intact — seule la type/nullabilité déclarée change).
            $table->string('payment_method')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cashier_id')->nullable(false)->change();
            $table->string('payment_method')->default('especes')->nullable(false)->change();
            $table->dropColumn('status');
        });
    }
};
