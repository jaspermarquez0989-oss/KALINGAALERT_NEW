@extends('layouts.app')

@section('title', $recipient->alert->title)

@php($alert = $recipient->alert)

@section('content')
<section class="page-heading"><div><p class="eyebrow">LOCATION-MATCHED ADVISORY</p><h1>{{ $alert->title }}</h1><p class="muted">{{ $alert->source->organization }} · Issued {{ $alert->published_at?->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</p></div><a class="button button-quiet" href="{{ route('resident.alerts.index') }}">Back to my alerts</a></section>
<div class="privacy-banner">This alert was made available because your registered location matched its selected coverage. It is not a substitute for official emergency instructions.</div>
<section class="alert-detail-grid">
    <article class="content-card">
        <div class="alert-detail-header"><div><span class="severity-pill {{ $alert->severity }}">{{ $alert->severity }}</span></div><span class="status-pill published">Active advisory</span></div>
        <div class="detail-block"><span class="detail-label">ADVISORY</span><p>{{ $alert->message }}</p></div>
        <div class="detail-block"><span class="detail-label">SAFETY INSTRUCTIONS</span><p>{{ $alert->safety_instructions }}</p></div>
        <div class="detail-block"><span class="detail-label">OFFICIAL INFORMATION</span><h2>{{ $alert->source->organization }} · {{ $alert->source->title }}</h2><p>{{ $alert->source->details }}</p>
            <div class="source-detail"><div><span class="detail-label">SOURCE ISSUE TIME</span><strong>{{ $alert->source->issued_at->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</strong></div><div><span class="detail-label">PUBLISHED</span><strong>{{ $alert->published_at?->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</strong></div></div>
            @if ($alert->source->source_url)<p class="source-link"><a href="{{ $alert->source->source_url }}" target="_blank" rel="noopener noreferrer">View original source ↗</a></p>@endif
        </div>
    </article>
    <article class="panel">
        <div class="panel-head"><div><h2>Affected coverage</h2><p>Mapped coverage selected by authorized personnel</p></div></div>
        <div class="map-frame"><div class="risk-map" data-risk-map></div></div>
        <script id="risk-map-data" type="application/json">@json($mapAreas)</script>
        <div class="map-legend"><span><i class="legend-dot covered"></i> Alert coverage</span></div>
        <p class="map-footnote">Your home location is private and is not shown on this map.</p>
    </article>
</section>
@endsection
