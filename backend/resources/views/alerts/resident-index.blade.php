@extends('layouts.app')

@section('title', 'My alerts')

@section('content')
<section class="page-heading"><div><p class="eyebrow">YOUR LOCATION-MATCHED ADVISORIES</p><h1>My alerts</h1><p class="muted">Only active alerts assigned to your registered location appear here.</p></div><a class="button button-quiet" href="{{ route('dashboard') }}">Overview</a></section>
<div class="privacy-banner">Web alerts are informational and depend on your internet connection and correct location details. Follow official warnings and safety instructions from local authorities.</div>
<section class="content-card alert-inbox">
    @forelse ($recipients as $recipient)
        @php($alert = $recipient->alert)
        <a class="inbox-item {{ $recipient->read_at ? 'is-read' : 'is-unread' }}" href="{{ route('resident.alerts.show', $alert) }}">
            <span class="severity-mark {{ $alert->severity }}"></span>
            <div class="inbox-main"><div class="inbox-heading"><h2>{{ $alert->title }}</h2><span class="severity-pill {{ $alert->severity }}">{{ $alert->severity }}</span></div><p>{{ $alert->source->organization }} · {{ $alert->riskAreas->pluck('barangay')->unique()->join(', ') }}</p><small>{{ $alert->published_at?->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') }}@if (! $recipient->read_at) · <b>NEW</b>@endif</small></div>
            <span class="inbox-arrow">→</span>
        </a>
    @empty
        <div class="empty-state"><b>No active alerts are matched to your location.</b>Check that your barangay and sitio/purok are current in <a href="{{ route('profile.edit') }}">account settings</a>.</div>
    @endforelse
    {{ $recipients->links() }}
</section>
@endsection
