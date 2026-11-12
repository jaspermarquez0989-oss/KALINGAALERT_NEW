@extends('layouts.app')

@section('title', 'Resident registration')

@section('content')
<section class="auth-layout">
    <div class="auth-aside"><p class="eyebrow">RESIDENT ACCESS</p><h1>Preparedness starts with knowing where you are.</h1><p>Your registered barangay and sitio/purok help match verified advisories with the locations they cover.</p><div class="privacy-box"><span class="privacy-lock">⌑</span><span><b>Your location stays private</b><small>Household coordinates are never shown on the map or to other residents.</small></span></div></div>
    <div class="auth-card auth-card-wide">
        <p class="eyebrow">CREATE AN ACCOUNT</p><h2>Resident registration</h2><p class="muted">Registration creates a resident account only. It cannot publish alerts or submit incident reports.</p>
        <form method="POST" action="{{ route('register.store') }}" class="form-stack">
            @csrf
            <div class="form-grid">
                <label>Full name<input name="name" value="{{ old('name') }}" autocomplete="name" required></label>
                <label>Email address<input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required></label>
                <label>Barangay<input name="barangay" value="{{ old('barangay') }}" autocomplete="address-level3" required maxlength="120" placeholder="Your barangay"></label>
                <label>Sitio / purok <span class="optional">(optional)</span><input name="sitio" value="{{ old('sitio') }}" maxlength="120" placeholder="Your sitio or purok"></label>
            </div>
            <details class="coordinate-details"><summary>Optional location coordinates for more precise matching</summary><p class="field-help">Only used to match alerts to mapped boundaries. These coordinates are private and never displayed publicly.</p>
                <div class="form-grid coordinate-grid">
                    <label>Latitude<input name="location_latitude" type="number" step="any" min="-90" max="90" value="{{ old('location_latitude') }}" placeholder="e.g. 12.73"></label>
                    <label>Longitude<input name="location_longitude" type="number" step="any" min="-180" max="180" value="{{ old('location_longitude') }}" placeholder="e.g. 121.36"></label>
                </div>
            </details>
            <div class="form-grid">
                <label>Password<input name="password" type="password" autocomplete="new-password" required minlength="10"><small>At least 10 characters, including letters and numbers.</small></label>
                <label>Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" required></label>
            </div>
            <button class="button button-full" type="submit">Create resident account <span aria-hidden="true">→</span></button>
        </form>
        <p class="auth-switch">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
    </div>
</section>
@endsection
