<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Authentification simple par token (Laravel Sanctum).
 *
 * V1 : un utilisateur = un compte, pas d'organisation ni d'équipe (spec §4).
 * Le token renvoyé est stocké dans le localStorage par le frontend, puis
 * renvoyé en `Authorization: Bearer {token}` sur toutes les requêtes.
 */
class AuthController extends Controller
{
    /**
     * POST /api/login
     *
     * Body : { email, password }
     * Succès : { token, user: { id, name, email } }
     * Erreur : 401 { message: "Identifiants invalides" }
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants invalides'],
            ]);
        }

        // Un seul token actif par session : on révoque les précédents pour
        // éviter d'accumuler des tokens orphelins côté navigateur.
        $user->tokens()->where('name', 'ticketlab')->delete();
        $token = $user->createToken('ticketlab')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    /**
     * POST /api/logout — révoque le token courant.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $token = $user->currentAccessToken();
            $token !== null ? $token->delete() : $user->tokens()->delete();
        }

        return response()->json(['message' => 'Déconnecté']);
    }

    /**
     * GET /api/me — utilisateur du token courant.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        return response()->json(['user' => $this->userPayload($user)]);
    }

    /**
     * Forme publique d'un utilisateur (jamais le mot de passe).
     *
     * @return array{id:int,name:string,email:string}
     */
    private function userPayload(User $user): array
    {
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
        ];
    }
}
