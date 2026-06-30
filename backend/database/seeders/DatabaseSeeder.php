<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 4 rôles du système
        foreach (['proprietaire', 'caissier', 'vendeur', 'gestionnaire'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // Compte propriétaire par défaut
        $admin = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name'      => 'Propriétaire',
                'password'  => bcrypt('admin123'),
                'is_active' => true,
            ]
        );
        $admin->assignRole('proprietaire');

        // Paramètres système par défaut
        $settings = [
            ['key' => 'boutique_name',          'value' => 'Boutique D'],
            ['key' => 'remise_max_sans_auth',    'value' => '10'],
            ['key' => 'remboursement_max',       'value' => '50000'],
            ['key' => 'ecart_caisse_alerte',     'value' => '2000'],
            ['key' => 'sortie_stock_max',        'value' => '20'],
            ['key' => 'session_inactivite_min',  'value' => '30'],
            ['key' => 'peremption_alerte_jours', 'value' => '7'],
        ];

        foreach ($settings as $s) {
            DB::table('settings')->updateOrInsert(
                ['key' => $s['key']],
                ['value' => $s['value'], 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
