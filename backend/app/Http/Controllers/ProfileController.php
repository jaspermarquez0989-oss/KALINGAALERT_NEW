<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($request->user()->id)],
            'barangay' => [
                Rule::requiredIf($request->user()->role === UserRole::CommunityResident->value),
                'nullable',
                'string',
                'max:120',
            ],
            'sitio' => ['nullable', 'string', 'max:120'],
            'location_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:location_longitude'],
            'location_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:location_latitude'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $attributes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'barangay' => $validated['barangay'] ?? null,
            'sitio' => $validated['sitio'] ?? null,
            'location_latitude' => $validated['location_latitude'] ?? null,
            'location_longitude' => $validated['location_longitude'] ?? null,
        ];

        if (! empty($validated['password'])) {
            $attributes['password'] = $validated['password'];
        }

        $request->user()->update($attributes);

        return back()->with('status', 'Your account details and alert location were updated.');
    }
}
