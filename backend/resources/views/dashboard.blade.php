@extends('layouts.app')

@php
    $roleLabels = \App\Enums\UserRole::labels();
    $isResident = auth()->user()->role === \App\Enums\UserRole::CommunityResident->value;
    $canViewAlerts = in_array(auth()->user()->role, [
        \App\Enums\UserRole::SystemAdministrator->value,
        \App\Enums\UserRole::MdrrmoPersonnel->value,
        \App\Enums\UserRole::AlertOperator->value,
    ], true);
@endphp

@section('title', 'Overview')

@section('content')
<div class="dashboard-greeting">
    <div><p class="eyebrow">{{ $isResident ? 'COMMUNITY ALERT CENTER' : 'OPERATIONS OVERVIEW' }}</p><h1>{{ $isResident ? 'Good day, '.auth()->user()->name : 'Welcome, '.auth()->user()->name }}</h1><p>{{ $isResident ? 'Location-based advisories and safety instructions for your registered area.' : 'Verified source information, mapped coverage, and targeted web alerts.' }}</p></div>
    <span class="role-chip">{{ $roleLabels[auth()->user()->role] ?? 'KalingaAlert user' }}</span>
</div>

@if ($isResident && ! auth()->user()->barangay)
    <div class="location-callout"><span>Add your barangay and sitio/purok so the system can match advisories to your registered location.</span><a class="button button-small-inline" href="{{ route('profile.edit') }}">Complete location</a></div>
@endif

<section class="stat-grid" aria-label="System summary">
    @if ($isResident)
        <article class="stat-card"><span>Active alerts for you</span><strong data-active-alert-count>{{ $stats['active_alerts'] }}</strong><em>Matched to your location</em></article>
        <article class="stat-card"><span>Unread advisories</span><strong data-alert-unread-count>{{ $stats['unread_alerts'] }}</strong><em>In your web inbox</em></article>
        <article class="stat-card"><span>Mapped risk areas</span><strong>{{ $stats['risk_areas'] }}</strong><em>Bongabong coverage</em></article>
        <article class="stat-card"><span>Your location</span><strong class="stat-location">{{ auth()->user()->barangay ? 'Set' : '—' }}</strong><em>{{ auth()->user()->barangay ?? 'Not set yet' }}</em></article>
    @else
        <article class="stat-card"><span>Mapped risk areas</span><strong>{{ $stats['risk_areas'] }}</strong><em>Active coverage</em></article>
        @if ($canViewAlerts)
            <article class="stat-card"><span>Current alerts</span><strong>{{ $stats['active_alerts'] }}</strong><em>Published and active</em></article>
            <article class="stat-card"><span>Matched residents</span><strong>{{ $stats['recipients'] }}</strong><em>Across current alerts</em></article>
        @else
            <article class="stat-card"><span>Source role</span><strong class="stat-location">BFP</strong><em>Submit verified fire information</em></article>
            <article class="stat-card"><span>Fire records</span><strong>{{ \App\Models\HazardSource::query()->where('source_type', 'fire')->count() }}</strong><em>Submitted source records</em></article>
        @endif
        <article class="stat-card"><span>New source reviews</span><strong>{{ \App\Models\HazardSource::query()->where('verification_status', 'pending')->count() }}</strong><em>Pending verification</em></article>
    @endif
</section>

@if ($isResident)
    <div class="privacy-banner">Your home coordinates are private and are never placed on this map. These boundaries show community risk coverage only. KalingaAlert is not a substitute for official emergency warnings.</div>
@endif

<section class="dashboard-grid">
    <article class="panel">
        <div class="panel-head"><div><h2>Risk-area map</h2><p>Verified vulnerable-area coverage managed by authorized personnel</p></div>
            @if (in_array(auth()->user()->role, [\App\Enums\UserRole::SystemAdministrator->value, \App\Enums\UserRole::MdrrmoPersonnel->value], true))
                <a class="text-link" href="{{ route('risk-areas.index') }}">Manage areas →</a>
            @endif
        </div>
        <div class="map-frame"><div id="dashboard-map" class="risk-map" data-risk-map></div></div>
        <script id="risk-map-data" type="application/json">@json($mapAreas)</script>
        <p class="map-footnote">Map locked to Bongabong municipality. Boundary: geoBoundaries (NAMRIA/PSA/OCHA), CC BY 3.0 IGO. Individual household locations are not displayed.</p>
        @if ($areas->isEmpty())
            <div class="empty-state"><b>No risk areas have been mapped yet.</b>Authorized MDRRMO personnel can add boundaries when the municipal coverage is confirmed.</div>
        @endif
    </article>

    <article class="panel">
        <div class="panel-head"><div><h2>{{ $isResident ? 'Your active alerts' : 'Active advisories' }}</h2><p>{{ $isResident ? 'Only alerts matched to your account are shown.' : 'Published web alerts for affected locations.' }}</p></div>
            @if ($isResident)
                <a class="text-link" href="{{ route('resident.alerts.index') }}">View all →</a>
            @elseif ($canViewAlerts)
                <a class="text-link" href="{{ route('alerts.index') }}">Manage →</a>
            @endif
        </div>
        <div class="alert-list">
            @forelse ($alerts as $alert)
                <article class="alert-list-item">
                    <span class="severity-mark {{ $alert->severity }}"></span>
                    <div>
                        <h3>
                            @if ($isResident)
                                <a href="{{ route('resident.alerts.show', $alert) }}">{{ $alert->title }}</a>
                            @elseif ($canViewAlerts)
                                <a href="{{ route('alerts.show', $alert) }}">{{ $alert->title }}</a>
                            @else
                                {{ $alert->title }}
                            @endif
                        </h3>
                        <p>{{ $alert->source->organization }} · {{ $alert->riskAreas->pluck('barangay')->unique()->join(', ') }}</p>
                        <time datetime="{{ $alert->published_at?->toIso8601String() }}">{{ $alert->published_at?->format('M j, Y · g:i A') }} · <span class="severity-pill {{ $alert->severity }}">{{ $alert->severity }}</span></time>
                    </div>
                </article>
            @empty
                <div class="empty-state"><b>{{ $isResident ? 'No active alerts for your location.' : 'No active published alerts.' }}</b>{{ $isResident ? 'When an approved advisory covers your registered area, it will appear here.' : 'Draft and approved alerts are managed in the alert workspace.' }}</div>
            @endforelse
        </div>
    </article>
</section>
@endsection
