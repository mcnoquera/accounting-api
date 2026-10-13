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
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            $this->audit->record('login_failed', null, null, ['email' => $request->string('email')->toString()]);
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
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
}
