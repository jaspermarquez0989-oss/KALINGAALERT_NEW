<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\RecipientMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request, RecipientMatcher $matcher): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'barangay' => ['required', 'string', 'max:120'],
            'sitio' => ['nullable', 'string', 'max:120'],
            'location_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:location_longitude'],
            'location_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:location_latitude'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $user = DB::transaction(function () use ($validated, $matcher): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'barangay' => $validated['barangay'],
                'sitio' => $validated['sitio'] ?? null,
                'location_latitude' => $validated['location_latitude'] ?? null,
                'location_longitude' => $validated['location_longitude'] ?? null,
                'password' => $validated['password'],
                'role' => UserRole::CommunityResident->value,
                'is_active' => true,
            ]);

            $matcher->deliverActiveAlertsToResident($user);

            return $user;
        });

        return $this->tokenResponse($request, Auth::guard('api')->login($user), $user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $token = Auth::guard('api')->attempt([...$credentials, 'is_active' => true]);

        if (! is_string($token)) {
            return response()->json(['message' => 'The supplied credentials could not be verified.'], 401);
        }

        return $this->tokenResponse($request, $token, Auth::guard('api')->user());
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => UserResource::make($request->user())->resolve($request),
        ]);
    }

    public function logout(): JsonResponse
    {
        Auth::guard('api')->logout();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $token = Auth::guard('api')->refresh();

        return $this->tokenResponse($request, $token, $request->user());
    }

    private function tokenResponse(Request $request, string $token, User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('jwt.ttl') * 60,
            'data' => UserResource::make($user)->resolve($request),
        ], $status);
    }
}
