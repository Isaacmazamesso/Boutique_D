<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class CheckSessionTimeout
{
    /**
     * Révoque le token si inactif depuis trop longtemps.
     *
     * Doit s'exécuter AVANT `auth:sanctum` : ce dernier met à jour
     * `last_used_at` dès qu'il résout l'utilisateur, donc lu après coup
     * la valeur refléterait toujours "maintenant".
     */
    public function handle(Request $request, Closure $next)
    {
        $plainToken = $request->bearerToken();

        if ($plainToken) {
            $accessToken = PersonalAccessToken::findToken($plainToken);

            if ($accessToken && $accessToken->last_used_at) {
                $timeoutMinutes = (int) Setting::getValue('inactivite_max_minutes', 30);

                if ($accessToken->last_used_at->lt(now()->subMinutes($timeoutMinutes))) {
                    $accessToken->delete();

                    return response()->json([
                        'success' => false,
                        'message' => 'Session expirée par inactivité. Veuillez vous reconnecter.',
                        'data'    => null,
                    ], 401);
                }
            }
        }

        return $next($request);
    }
}
