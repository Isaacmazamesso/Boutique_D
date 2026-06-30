<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => $this->formatUser($u));

        return $this->success($users);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => 'required|string|max:100',
            'username' => 'required|string|max:50|unique:users',
            'password' => 'required|string|min:6',
            'role'     => 'required|in:caissier,vendeur,gestionnaire,proprietaire',
            'phone'    => 'nullable|string|max:20',
        ]);

        $user = User::create([
            'name'      => $request->name,
            'username'  => $request->username,
            'password'  => Hash::make($request->password),
            'phone'     => $request->phone,
            'is_active' => true,
        ]);

        $user->assignRole($request->role);

        activity_log($request->user()->id, 'creation_utilisateur', 'User', $user->id, [
            'username' => $user->username,
            'role'     => $request->role,
        ]);

        return $this->success($this->formatUser($user->load('roles')), 'Utilisateur créé avec succès.', 201);
    }

    public function show(User $user): JsonResponse
    {
        return $this->success($this->formatUser($user->load('roles')));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'name'  => 'sometimes|string|max:100',
            'phone' => 'nullable|string|max:20',
            'role'  => 'sometimes|in:caissier,vendeur,gestionnaire,proprietaire',
        ]);

        // Empêcher de retirer le dernier propriétaire actif
        if ($request->role && $request->role !== 'proprietaire') {
            $currentRole = $user->getRoleNames()->first();
            if ($currentRole === 'proprietaire' && $this->activeProprietairesCount() <= 1) {
                return $this->error('Impossible : il doit rester au moins un propriétaire actif.', 422);
            }
        }

        $user->update($request->only(['name', 'phone']));

        if ($request->role) {
            $user->syncRoles([$request->role]);
        }

        activity_log($request->user()->id, 'modification_utilisateur', 'User', $user->id);

        return $this->success($this->formatUser($user->load('roles')), 'Utilisateur mis à jour.');
    }

    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        // Empêcher la désactivation du dernier propriétaire actif
        if ($user->is_active && $user->hasRole('proprietaire') && $this->activeProprietairesCount() <= 1) {
            return $this->error('Impossible : il doit rester au moins un propriétaire actif.', 422);
        }

        $user->update(['is_active' => !$user->is_active]);

        // Révoquer les tokens si désactivé
        if (!$user->is_active) {
            $user->tokens()->delete();
        }

        $action = $user->is_active ? 'activation_utilisateur' : 'desactivation_utilisateur';
        activity_log($request->user()->id, $action, 'User', $user->id);

        $msg = $user->is_active ? 'Compte activé.' : 'Compte désactivé.';
        return $this->success(['is_active' => $user->is_active], $msg);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $user->update(['password' => Hash::make($request->password)]);
        $user->tokens()->delete();

        activity_log($request->user()->id, 'reinitialisation_mot_de_passe', 'User', $user->id);

        return $this->success(null, 'Mot de passe réinitialisé. L\'utilisateur doit se reconnecter.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        // Ne pas supprimer son propre compte
        if ($user->id === $request->user()->id) {
            return $this->error('Vous ne pouvez pas supprimer votre propre compte.', 422);
        }

        // Vérifier si l'utilisateur a des transactions
        if ($this->userHasTransactions($user)) {
            return $this->error('Impossible : cet utilisateur a des transactions. Désactivez-le plutôt.', 422);
        }

        activity_log($request->user()->id, 'suppression_utilisateur', 'User', $user->id, [
            'username' => $user->username,
        ]);

        $user->delete();

        return $this->success(null, 'Utilisateur supprimé.');
    }

    public function logs(User $user): JsonResponse
    {
        $logs = $user->activityLogs()
            ->latest()
            ->limit(100)
            ->get(['action', 'model_type', 'model_id', 'details', 'ip_address', 'created_at']);

        return $this->success($logs);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function activeProprietairesCount(): int
    {
        return User::role('proprietaire')->where('is_active', true)->count();
    }

    private function userHasTransactions(User $user): bool
    {
        return $user->salesAsCashier()->exists()
            || $user->stockEntries()->exists()
            || $user->stockExits()->exists();
    }

    private function formatUser(User $user): array
    {
        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'username'   => $user->username,
            'phone'      => $user->phone,
            'photo'      => $user->photo,
            'role'       => $user->getRoleNames()->first(),
            'is_active'  => $user->is_active,
            'created_at' => $user->created_at->format('d/m/Y'),
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
