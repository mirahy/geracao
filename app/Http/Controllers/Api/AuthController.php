<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            ActivityLogger::access('login_failed', 'Tentativa de login recusada: '.$data['email'], $user, ['email' => $data['email']]);

            throw ValidationException::withMessages(['email' => ['E-mail ou senha inválidos.']]);
        }

        if ($user->isPending()) {
            ActivityLogger::access('login_failed', 'Login recusado: cadastro aguardando aprovação', $user, ['email' => $data['email']]);

            throw ValidationException::withMessages(['email' => ['Cadastro aguardando aprovação.']]);
        }

        $minutes = (int) config('sanctum.expiration');
        $expiresAt = $minutes > 0 ? now()->addMinutes($minutes) : null;
        $token = $user->createToken($data['device_name'] ?? 'mobile', ['*'], $expiresAt);

        ActivityLogger::access('login', 'Entrou no app', $user, ['device' => $data['device_name'] ?? 'mobile']);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt?->toIso8601String(),
            'user' => $this->present($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        ActivityLogger::access('logout', 'Saiu do app', $request->user());
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->present($request->user())]);
    }

    /** O próprio usuário altera nome e e-mail. */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update($data);

        return response()->json(['user' => $this->present($user->fresh())]);
    }

    /** Troca a senha exigindo a atual; as demais sessões do app são encerradas. */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['A senha atual está incorreta.']]);
        }

        $user->update(['password' => $data['password']]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(null, 204);
    }

    /** @return array{id: int, name: string, email: string, permissions: list<string>} */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
