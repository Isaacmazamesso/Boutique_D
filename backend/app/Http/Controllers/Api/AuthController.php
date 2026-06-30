<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)->first();

        // Utilisateur introuvable
        if (!$user) {
            return $this->error('Identifiants incorrects.', 401);
        }

        // Compte désactivé
        if (!$user->is_active) {
            return $this->error('Ce compte a été désactivé. Contactez le propriétaire.', 403);
        }

        // Compte verrouillé
        if ($user->isLocked()) {
            $minutes = now()->diffInMinutes($user->locked_until);
            return $this->error("Compte verrouillé. Réessayez dans {$minutes} minute(s).", 429);
        }

        // Mauvais mot de passe
        if (!Hash::check($request->password, $user->password)) {
            $user->increment('failed_attempts');

            if ($user->failed_attempts >= 5) {
                $user->update(['locked_until' => now()->addMinutes(30)]);
                return $this->error('Trop de tentatives. Compte verrouillé 30 minutes.', 429);
            }

            $restants = 5 - $user->failed_attempts;
            return $this->error("Mot de passe incorrect. {$restants} tentative(s) restante(s).", 401);
        }

        // Succès — réinitialiser les tentatives
        $user->update(['failed_attempts' => 0, 'locked_until' => null]);

        // Révoquer les anciens tokens et en créer un nouveau
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        // Logger la connexion
        activity_log($user->id, 'connexion', null, null, [
            'device' => $request->userAgent(),
        ]);

        return $this->success([
            'token' => $token,
            'user'  => $this->formatUser($user),
        ], 'Connexion réussie.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($this->formatUser($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        activity_log($request->user()->id, 'deconnexion');
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Déconnexion réussie.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->error('Mot de passe actuel incorrect.', 422);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        activity_log($user->id, 'changement_mot_de_passe');

        return $this->success(null, 'Mot de passe modifié avec succès.');
    }

    private function formatUser(User $user): array
    {
        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'username' => $user->username,
            'phone'    => $user->phone,
            'photo'    => $user->photo,
            'role'     => $user->getRoleNames()->first(),
        ];
    }

    private function success(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    private function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
        ], $status);
    }
}
