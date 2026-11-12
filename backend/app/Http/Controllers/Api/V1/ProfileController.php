<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->profileData($request),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'barangay' => [
                Rule::requiredIf($user->role === 'resident'),
                'nullable',
                'string',
                'max:120',
            ],
            'sitio' => ['nullable', 'string', 'max:120'],
            'location_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:location_longitude'],
            'location_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:location_latitude'],
            'current_password' => ['nullable', 'required_with:password', 'string'],
            'password' => ['nullable', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        if (
            filled($validated['password'] ?? null)
            && ! Hash::check($validated['current_password'], $user->password)
        ) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $attributes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'barangay' => $validated['barangay'] ?? null,
            'sitio' => $validated['sitio'] ?? null,
            'location_latitude' => $validated['location_latitude'] ?? null,
            'location_longitude' => $validated['location_longitude'] ?? null,
        ];

        if (filled($validated['password'] ?? null)) {
            $attributes['password'] = $validated['password'];
        }

        $user->update($attributes);

        return response()->json([
            'message' => 'Profile updated.',
            'data' => $this->profileData($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileData(Request $request): array
    {
        $user = $request->user();

        return [
            ...UserResource::make($user)->resolve($request),
            'location_latitude' => $user->location_latitude,
            'location_longitude' => $user->location_longitude,
        ];
    }
}
