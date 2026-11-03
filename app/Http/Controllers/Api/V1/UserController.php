<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Services\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Admin-only user management. Users are never deleted, only deactivated, so the
 * audit trail keeps pointing at real people.
 */
class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(): JsonResponse
    {
        Gate::authorize('manage-users');

        return ApiResponse::ok($this->users->list());
    }

    public function store(UserRequest $request): JsonResponse
    {
        Gate::authorize('manage-users');

        return ApiResponse::ok($this->users->create($request->validated()), 201);
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('manage-users');

        return ApiResponse::ok($user);
    }

    public function update(UserRequest $request, User $user): JsonResponse
    {
        Gate::authorize('manage-users');

        return ApiResponse::ok($this->users->update($user, $request->validated(), $request->user()));
    }

    public function unlock(User $user): JsonResponse
    {
        Gate::authorize('manage-users');

        return ApiResponse::ok($this->users->unlock($user));
    }
}
