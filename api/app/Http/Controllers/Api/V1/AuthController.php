<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::withoutGlobalScopes()->where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            Audit::record('auth.login_failed', null, ['email' => $request->email]);
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is disabled.']);
        }

        if ($user->tenant && ! $user->tenant->isActive()) {
            return response()->json(['message' => 'This operator account is not active.'], 403);
        }

        // Bind tenant context so roles resolve against the right team.
        if ($user->tenant) {
            app(TenantContext::class)->set($user->tenant);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('api', expiresAt: now()->addHours(12))->plainTextToken;

        Audit::record('auth.login', null, null, $user->id, $user->tenant_id);

        return response()->json([
            'token' => $token,
            'user' => $this->profile($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        Audit::record('auth.logout');

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If that account exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:10|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete(); // revoke all sessions on password change
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password has been reset.'])
            : response()->json(['message' => 'Invalid or expired reset token.'], 422);
    }

    private function profile(User $user): array
    {
        if ($user->tenant) {
            app(TenantContext::class)->set($user->tenant);
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tenant_id' => $user->tenant_id,
            'is_platform_user' => $user->is_platform_user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'tenant' => $user->tenant ? [
                'id' => $user->tenant->id,
                'slug' => $user->tenant->slug,
                'name' => $user->tenant->trading_name,
                'currency' => $user->tenant->currency,
                'timezone' => $user->tenant->timezone,
                'branding' => $user->tenant->branding,
            ] : null,
        ];
    }
}
