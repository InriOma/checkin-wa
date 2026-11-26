<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\LoginRequest;
use App\Http\Resources\V1\TenantResource;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $tokenName = $credentials['device_name'] ?? 'api-client';
        $token = $user->createToken($tokenName)->plainTextToken;

        $user->load(['memberships' => fn ($q) => $q->where('status', 'active')->with('tenant')]);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $user->load(['memberships' => fn ($q) => $q->where('status', 'active')->with('tenant')]);

        return new UserResource($user);
    }

    public function tenants(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $tenants = $user->tenants()
            ->wherePivot('status', 'active')
            ->orderBy('tenants.name')
            ->get();

        return TenantResource::collection($tenants);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}
