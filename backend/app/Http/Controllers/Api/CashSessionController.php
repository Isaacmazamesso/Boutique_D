<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashSessionController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $session = CashSession::where('cashier_id', $request->user()->id)
            ->whereNull('closed_at')
            ->latest()
            ->first();

        if (!$session) {
            return $this->success(null, 'Aucune session ouverte.');
        }

        return $this->success($this->formatSession($session));
    }

    public function open(Request $request): JsonResponse
    {
        // Vérifier qu'il n'y a pas déjà une session ouverte
        $existing = CashSession::where('cashier_id', $request->user()->id)
            ->whereNull('closed_at')
            ->first();

        if ($existing) {
            return $this->error('Vous avez déjà une session de caisse ouverte.', 422);
        }

        $request->validate([
            'opening_amount' => 'required|integer|min:0',
        ]);

        $session = CashSession::create([
            'cashier_id'     => $request->user()->id,
            'opening_amount' => $request->opening_amount,
            'opened_at'      => now(),
        ]);

        activity_log($request->user()->id, 'ouverture_caisse', 'CashSession', $session->id, [
            'montant_ouverture' => $request->opening_amount,
        ]);

        return $this->success($this->formatSession($session), 'Session de caisse ouverte.', 201);
    }

    public function close(Request $request): JsonResponse
    {
        $session = CashSession::where('cashier_id', $request->user()->id)
            ->whereNull('closed_at')
            ->first();

        if (!$session) {
            return $this->error('Aucune session de caisse ouverte.', 404);
        }

        $request->validate([
            'closing_amount' => 'required|integer|min:0',
        ]);

        $theoretical = $session->theoreticalAmount();
        $difference  = $request->closing_amount - $theoretical;

        $session->update([
            'closing_amount'     => $request->closing_amount,
            'theoretical_amount' => $theoretical,
            'difference'         => $difference,
            'closed_at'          => now(),
        ]);

        activity_log($request->user()->id, 'fermeture_caisse', 'CashSession', $session->id, [
            'montant_saisi'   => $request->closing_amount,
            'montant_theorique' => $theoretical,
            'ecart'           => $difference,
        ]);

        // Alerte si écart dépasse le seuil
        $seuil = (int) Setting::getValue('ecart_caisse_alerte', 2000);
        $alerte = abs($difference) > $seuil;

        return $this->success([
            'session'            => $this->formatSession($session),
            'theoretical_amount' => $theoretical,
            'difference'         => $difference,
            'alerte_ecart'       => $alerte,
        ], $alerte
            ? "Session fermée. ATTENTION : écart de {$difference} FCFA détecté !"
            : 'Session de caisse fermée.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $query = CashSession::with('cashier:id,name')
            ->when(!$request->user()->hasRole('proprietaire'), fn($q) =>
                $q->where('cashier_id', $request->user()->id)
            )
            ->when($request->date, fn($q) =>
                $q->whereDate('opened_at', $request->date)
            )
            ->latest('opened_at');

        return $this->success($query->limit(50)->get()->map(fn($s) => $this->formatSession($s)));
    }

    private function formatSession(CashSession $session): array
    {
        return [
            'id'                 => $session->id,
            'cashier'            => $session->cashier?->name,
            'opening_amount'     => $session->opening_amount,
            'closing_amount'     => $session->closing_amount,
            'theoretical_amount' => $session->theoretical_amount,
            'difference'         => $session->difference,
            'total_sales'        => $session->isOpen() ? $session->totalSales() : null,
            'is_open'            => $session->isOpen(),
            'opened_at'          => $session->opened_at->format('d/m/Y H:i'),
            'closed_at'          => $session->closed_at?->format('d/m/Y H:i'),
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
