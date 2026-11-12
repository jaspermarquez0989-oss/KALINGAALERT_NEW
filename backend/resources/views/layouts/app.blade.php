<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'KalingaAlert') · KalingaAlert</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="site-header">
        <a class="brand" href="{{ route('home') }}">
            <span class="brand-mark" aria-hidden="true">K</span>
            <span>Kalinga<span class="brand-light">Alert</span><small>Bongabong · Oriental Mindoro</small></span>
        </a>
        <nav class="main-nav" aria-label="Main navigation">
            @auth
                <a href="{{ route('dashboard') }}">Overview</a>
                @if (auth()->user()->role === \App\Enums\UserRole::CommunityResident->value)
                    <a href="{{ route('resident.alerts.index') }}">My alerts <span class="nav-count" data-alert-status-url="{{ route('resident.alerts.status') }}" data-alert-unread-count>{{ auth()->user()->unreadAlertCount() }}</span></a>
                @else
                    <a href="{{ route('risk-areas.index') }}">Risk areas</a>
                    <a href="{{ route('hazard-sources.index') }}">Sources</a>
                    @if (in_array(auth()->user()->role, [\App\Enums\UserRole::SystemAdministrator->value, \App\Enums\UserRole::MdrrmoPersonnel->value, \App\Enums\UserRole::AlertOperator->value], true))
                        <a href="{{ route('alerts.index') }}">Alerts</a>
                    @endif
                    @if (auth()->user()->role === \App\Enums\UserRole::SystemAdministrator->value)
                        <a href="{{ route('staff.index') }}">Staff</a>
                        <a href="{{ route('staff.audit') }}">Audit</a>
                    @endif
                @endif
                <a href="{{ route('profile.edit') }}">My account</a>
                <form method="POST" action="{{ route('logout') }}" class="nav-logout">@csrf<button type="submit">Sign out</button></form>
            @else
                <a href="{{ route('login') }}">Sign in</a>
                <a class="button button-small" href="{{ route('register') }}">Resident registration</a>
            @endauth
        </nav>
    </header>

    <main class="page-shell">
        @auth
            @if (auth()->user()->role === \App\Enums\UserRole::CommunityResident->value)
                <div class="resident-alert-notifications" data-resident-alert-notifications aria-live="polite" aria-relevant="additions"></div>
                <div class="resident-alert-sync-status" data-resident-alert-sync-status role="status" hidden></div>
            @endif
        @endauth
        @if (session('status'))
            <div class="notice notice-success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="notice notice-error" role="alert">
                <strong>Please review the following:</strong>
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('content')
    </main>

    <footer class="site-footer">
        <span>KalingaAlert is an information and preparedness tool.</span>
        <span>Follow official emergency instructions from local authorities.</span>
    </footer>
</body>
</html>
