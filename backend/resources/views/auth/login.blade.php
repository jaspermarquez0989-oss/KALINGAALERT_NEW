@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
<section class="auth-layout">
    <div class="auth-aside"><p class="eyebrow">WELCOME BACK</p><h1>Stay informed.<br>Stay prepared.</h1><p>Sign in to see advisories and safety instructions relevant to your registered location.</p><div class="auth-aside-line"></div><small>Official source · Local coverage · Clear guidance</small></div>
    <div class="auth-card">
        <p class="eyebrow">KALINGAALERT ACCOUNT</p><h2>Sign in</h2><p class="muted">Use your registered email and password.</p>
        <form method="POST" action="{{ route('login.store') }}" class="form-stack">
            @csrf
            <label>Email address<input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
            <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
            <label class="check-row"><input name="remember" type="checkbox" value="1"> Keep me signed in on this device</label>
            <button class="button button-full" type="submit">Sign in <span aria-hidden="true">→</span></button>
        </form>
        <p class="auth-switch">New to KalingaAlert? <a href="{{ route('register') }}">Register as a resident</a></p>
    </div>
</section>
@endsection
