<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\RecipientMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([...$credentials, 'is_active' => true], $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'The supplied credentials could not be verified.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function register(Request $request, RecipientMatcher $matcher): RedirectResponse
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

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Your resident account is ready.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
