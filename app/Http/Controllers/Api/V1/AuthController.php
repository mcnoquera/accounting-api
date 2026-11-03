<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();
        $user = User::where('email', $email)->first();

        if ($user?->isLocked()) {
            $this->audit->record('login_locked', $user, null, ['email' => $email]);
            $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));
            throw ValidationException::withMessages(['email' => [
                "Too many failed sign-ins. This account is locked for {$minutes} more minute(s).",
            ]]);
        }

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            $this->recordFailure($user, $email);
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        if (! $user->is_active) {
            $this->audit->record('login_failed', $user, null, ['email' => $email, 'reason' => 'inactive']);
            throw ValidationException::withMessages(['email' => ['This account has been deactivated. Contact your administrator.']]);
        }

        if ($user->failed_login_attempts > 0 || $user->locked_until !== null) {
            $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->saveQuietly();
        }

        $token = $user->createToken('web')->plainTextToken;
        $this->audit->record('login', $user, null, null, $user->id);

        return ApiResponse::ok(['token' => $token, 'user' => $user]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->record('logout', $request->user());
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::ok(null);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok($request->user());
    }

    /** Counts a failed sign-in and locks the account after MAX_LOGIN_ATTEMPTS. */
    private function recordFailure(?User $user, string $email): void
    {
        $this->audit->record('login_failed', $user, null, ['email' => $email]);
        if (! $user) {
            return;
        }

        $attempts = $user->failed_login_attempts + 1;
        if ($attempts >= User::MAX_LOGIN_ATTEMPTS) {
            $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => now()->addMinutes(User::LOCKOUT_MINUTES)])->saveQuietly();
            $this->audit->record('locked_out', $user, null, ['minutes' => User::LOCKOUT_MINUTES]);

            return;
        }

        $user->forceFill(['failed_login_attempts' => $attempts])->saveQuietly();
    }
}
