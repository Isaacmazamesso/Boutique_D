<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    private const SCHEMA = [
        'remise_max_sans_auth' => [
            'label'       => 'Remise max. sans autorisation',
            'description' => "Au-delà de ce pourcentage de remise sur une vente, l'autorisation du propriétaire est requise.",
            'unit'        => '%',
            'default'     => 10,
            'min'         => 0,
            'max'         => 100,
        ],
        'remboursement_max' => [
            'label'       => 'Remboursement max. sans autorisation',
            'description' => "Au-delà de ce montant, un remboursement nécessite l'autorisation du propriétaire.",
            'unit'        => 'FCFA',
            'default'     => 50000,
            'min'         => 0,
            'max'         => null,
        ],
        'sortie_stock_max' => [
            'label'       => 'Sortie de stock max. sans autorisation',
            'description' => "Au-delà de cette quantité, une sortie de stock nécessite l'autorisation du propriétaire.",
            'unit'        => 'unités',
            'default'     => 20,
            'min'         => 1,
            'max'         => null,
        ],
        'ecart_caisse_alerte' => [
            'label'       => "Seuil d'alerte écart de caisse",
            'description' => "Au-delà de cet écart (valeur absolue) entre le montant théorique et le montant compté à la fermeture de caisse, une alerte est déclenchée.",
            'unit'        => 'FCFA',
            'default'     => 2000,
            'min'         => 0,
            'max'         => null,
        ],
        'peremption_alerte_jours' => [
            'label'       => 'Alerte péremption (jours avant)',
            'description' => "Nombre de jours avant la date de péremption à partir duquel un produit apparaît dans les alertes.",
            'unit'        => 'jours',
            'default'     => 7,
            'min'         => 0,
            'max'         => null,
        ],
    ];

    public function index(): JsonResponse
    {
        return $this->success($this->currentValues());
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->all();

        foreach (array_keys($payload) as $key) {
            if (!array_key_exists($key, self::SCHEMA)) {
                return $this->error("Paramètre inconnu : {$key}.", 422);
            }
        }

        $rules = [];
        foreach (self::SCHEMA as $key => $def) {
            $rule = 'sometimes|integer|min:' . $def['min'];
            if ($def['max'] !== null) {
                $rule .= '|max:' . $def['max'];
            }
            $rules[$key] = $rule;
        }

        $validated = $request->validate($rules);

        if (empty($validated)) {
            return $this->error('Aucun paramètre à mettre à jour.', 422);
        }

        foreach ($validated as $key => $value) {
            Setting::setValue($key, (string) $value);
        }

        activity_log($request->user()->id, 'modification_parametres', null, null, [
            'changes' => $validated,
        ]);

        return $this->success($this->currentValues(), 'Paramètres mis à jour.');
    }

    private function currentValues(): array
    {
        $result = [];
        foreach (self::SCHEMA as $key => $def) {
            $result[] = [
                'key'         => $key,
                'label'       => $def['label'],
                'description' => $def['description'],
                'unit'        => $def['unit'],
                'value'       => (int) Setting::getValue($key, $def['default']),
                'default'     => $def['default'],
                'min'         => $def['min'],
                'max'         => $def['max'],
            ];
        }

        return $result;
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
