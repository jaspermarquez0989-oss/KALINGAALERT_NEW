@extends('layouts.app')

@section('title', $alert->exists ? 'Revise alert draft' : 'Prepare an alert')

@section('content')
<section class="page-heading"><div><p class="eyebrow">TARGETED ADVISORIES</p><h1>{{ $alert->exists ? 'Edit alert draft' : 'Prepare an alert' }}</h1><p class="muted">Only verified PAGASA or BFP source records can be used.</p></div><a class="button button-quiet" href="{{ route('alerts.index') }}">Back to alerts</a></section>
<div class="privacy-banner">Alerts are not sent to all accounts. Publication matches registered barangay/sitio or private coordinates against the selected coverage; delivery is limited to this web application.</div>
<section class="content-card">
    @if ($sources->isEmpty() || $areas->isEmpty())
        <div class="empty-state"><b>Preparation prerequisites are missing.</b>At least one verified source record and one active mapped risk area are required before drafting.</div>
    @else
        <form method="POST" action="{{ $alert->exists ? route('alerts.update', $alert) : route('alerts.store') }}" class="form-stack">
            @csrf
            @if ($alert->exists) @method('PUT') @endif
            <div class="form-grid">
                <label>Verified official source<select name="hazard_source_id" required>
                    <option value="">Select a verified record</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source->id }}" @selected((string) old('hazard_source_id', $alert->hazard_source_id) === (string) $source->id)>{{ $source->organization }} · {{ $source->title }} · {{ $source->issued_at->format('M j, Y') }}</option>
                    @endforeach
                </select></label>
                <label>Severity<select name="severity" required>
                    @foreach (['advisory' => 'Advisory', 'watch' => 'Watch', 'warning' => 'Warning', 'emergency' => 'Emergency'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('severity', $alert->severity) === $value)>{{ $label }}</option>
                    @endforeach
                </select></label>
            </div>
            <label>Alert title<input name="title" value="{{ old('title', $alert->title) }}" required maxlength="180"></label>
            <label>Public advisory message<textarea name="message" rows="5" required maxlength="5000">{{ old('message', $alert->message) }}</textarea></label>
            <label>Safety instructions<textarea name="safety_instructions" rows="5" required maxlength="5000" placeholder="Use only verified, practical safety guidance.">{{ old('safety_instructions', $alert->safety_instructions) }}</textarea></label>
            <div class="form-grid">
                <label>Start time (Philippine time)<input name="starts_at" type="datetime-local" value="{{ old('starts_at', $alert->starts_at?->copy()->timezone('Asia/Manila')->format('Y-m-d\TH:i')) }}" required></label>
                <label>Expiry time <span class="optional">(optional, Philippine time)</span><input name="ends_at" type="datetime-local" value="{{ old('ends_at', $alert->ends_at?->copy()->timezone('Asia/Manila')->format('Y-m-d\TH:i')) }}"></label>
            </div>
            <div class="form-section-title">Select affected coverage</div>
            <div class="risk-area-select">
                @php($selectedAreas = collect(old('risk_area_ids', $alert->exists ? $alert->riskAreas->modelKeys() : []))->map(fn ($id) => (int) $id)->all())
                @foreach ($areas as $area)
                    <label class="risk-area-option"><input type="checkbox" name="risk_area_ids[]" value="{{ $area->id }}" @checked(in_array($area->id, $selectedAreas, true))><span><b>{{ $area->name }}</b><small>{{ $area->sitio ? $area->sitio.', ' : '' }}{{ $area->barangay }} · {{ $area->classification }}</small></span></label>
                @endforeach
            </div>
            <div class="page-actions"><button class="button" type="submit">{{ $alert->exists ? 'Save draft changes' : 'Save & request approval' }}</button><a class="button button-quiet" href="{{ route('alerts.index') }}">Cancel</a></div>
        </form>
    @endif
</section>
@endsection
