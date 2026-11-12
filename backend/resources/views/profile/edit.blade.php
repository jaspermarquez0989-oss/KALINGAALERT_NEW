@extends('layouts.app')

@section('title', 'My account')

@section('content')
<section class="page-heading"><div><p class="eyebrow">ACCOUNT SETTINGS</p><h1>My account</h1><p class="muted">Update your contact details and alert location.</p></div></section>
<section class="content-card narrow-card">
    <form method="POST" action="{{ route('profile.update') }}" class="form-stack">
        @csrf @method('PUT')
        <label>Full name<input name="name" value="{{ old('name', $user->name) }}" required></label>
        <label>Email address<input name="email" type="email" value="{{ old('email', $user->email) }}" required></label>
        @if ($user->role === \App\Enums\UserRole::CommunityResident->value)
            <div class="form-grid">
                <label>Barangay<input name="barangay" value="{{ old('barangay', $user->barangay) }}" required></label>
                <label>Sitio / purok<input name="sitio" value="{{ old('sitio', $user->sitio) }}"></label>
            </div>
            <details class="coordinate-details"><summary>Optional private coordinates</summary><div class="form-grid coordinate-grid">
                <label>Latitude<input name="location_latitude" type="number" step="any" min="-90" max="90" value="{{ old('location_latitude', $user->location_latitude) }}"></label>
                <label>Longitude<input name="location_longitude" type="number" step="any" min="-180" max="180" value="{{ old('location_longitude', $user->location_longitude) }}"></label>
            </div><p class="field-help">Coordinates are private and used only for matching against a mapped alert boundary.</p></details>
        @endif
        <div class="form-section-title">Change password <span class="optional">(optional)</span></div>
        <label>Current password<input name="current_password" type="password" autocomplete="current-password"></label>
        <div class="form-grid">
            <label>New password<input name="password" type="password" autocomplete="new-password" minlength="10"><small>At least 10 characters, including letters and numbers.</small></label>
            <label>Confirm new password<input name="password_confirmation" type="password" autocomplete="new-password"></label>
        </div>
        <button class="button" type="submit">Save account details</button>
    </form>
</section>
@endsection
