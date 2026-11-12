@extends('layouts.app')

@section('title', 'Community hazard alerts')

@section('content')
<section class="hero">
    <div class="hero-copy">
        <p class="eyebrow"><span class="eyebrow-dot"></span> Community preparedness · Bongabong, Oriental Mindoro</p>
        <h1>Warnings for the places that need them.</h1>
        <p class="hero-lede">KalingaAlert connects verified PAGASA weather bulletins and BFP fire information with MDRRMO-managed risk areas, then makes relevant advisories available to registered residents in those locations.</p>
        <div class="hero-actions">
            <a class="button" href="{{ route('register') }}">Register as a resident <span aria-hidden="true">→</span></a>
            <a class="button button-quiet" href="{{ route('login') }}">Staff sign in</a>
        </div>
        <div class="hero-note"><span class="note-icon">i</span><span>This website does not predict hazards, accept incident reports, or dispatch rescue. It does not replace official public warnings or emergency instructions.</span></div>
    </div>
    <div class="hero-art" aria-label="Illustration of mapped community risk zones">
        <div class="art-label"><span class="live-dot"></span> LOCATION-BASED ADVISORIES</div>
        <div class="art-map">
            <span class="contour contour-one"></span><span class="contour contour-two"></span><span class="contour contour-three"></span>
            <span class="map-pin pin-one"><i></i></span><span class="map-pin pin-two"><i></i></span><span class="map-pin pin-three"><i></i></span>
            <div class="map-caption"><span class="caption-icon">⌖</span><span><b>Risk-area coverage</b><small>Managed by authorized local personnel</small></span></div>
        </div>
        <div class="hero-source"><span class="source-seal">✓</span><span><b>Official sources</b><small>PAGASA · Bureau of Fire Protection</small></span><span class="source-arrow">↗</span></div>
    </div>
</section>

<section class="principles">
    <div class="section-heading">
        <div><p class="eyebrow">HOW IT WORKS</p><h2>Local information. Clear guidance.</h2></div>
        <p>Every advisory carries its source, affected coverage, issue time, and practical safety instructions.</p>
    </div>
    <div class="principle-grid">
        <article class="principle-card"><span class="step-number">01</span><div class="principle-icon icon-map">⌖</div><h3>Map vulnerable areas</h3><p>Authorized MDRRMO personnel maintain the mapped coverage used to target advisories.</p></article>
        <article class="principle-card"><span class="step-number">02</span><div class="principle-icon icon-source">✓</div><h3>Verify the source</h3><p>Regional PAGASA warnings are imported for staff review; BFP-confirmed fire information is recorded by staff.</p></article>
        <article class="principle-card"><span class="step-number">03</span><div class="principle-icon icon-alert">↗</div><h3>Reach matched residents</h3><p>Approved web alerts are shown only to registered residents matched to selected areas.</p></article>
    </div>
</section>

<section class="resident-cta">
    <div><p class="eyebrow">FOR BONGABONG RESIDENTS</p><h2>Keep your alert location up to date.</h2><p>Register with your barangay and sitio/purok. Your individual home location is private and is never displayed on public maps.</p></div>
    <a class="button button-light" href="{{ route('register') }}">Create resident account <span aria-hidden="true">→</span></a>
</section>
@endsection
