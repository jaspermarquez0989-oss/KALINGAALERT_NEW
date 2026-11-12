@extends('layouts.app')

@section('title', 'Add source record')

@section('content')
<section class="page-heading"><div><p class="eyebrow">OFFICIAL INFORMATION</p><h1>Record a source bulletin</h1><p class="muted">Submitted records remain pending until reviewed by a different authorized MDRRMO user.</p></div><a class="button button-quiet" href="{{ route('hazard-sources.index') }}">Back to sources</a></section>
<section class="content-card narrow-card">
    <form method="POST" action="{{ route('hazard-sources.store') }}" class="form-stack">
        @csrf
        <label>Information type<select name="source_type" required>
            @if (auth()->user()->role !== \App\Enums\UserRole::BfpOfficer->value)<option value="weather" @selected(old('source_type') === 'weather')>PAGASA weather bulletin or advisory</option>@endif
            <option value="fire" @selected(old('source_type') === 'fire')>BFP-confirmed fire information</option>
        </select></label>
        <div class="form-grid">
            <label>Bulletin / incident title<input name="title" value="{{ old('title') }}" maxlength="180" required placeholder="Official bulletin title"></label>
            <label>Reference number <span class="optional">(if available)</span><input name="reference" value="{{ old('reference') }}" maxlength="150"></label>
        </div>
        <label>Details from the official source<textarea name="details" maxlength="10000" required rows="6" placeholder="Enter the relevant source information without adding predictions.">{{ old('details') }}</textarea></label>
        <div class="form-grid">
            <label>Source URL <span class="optional">(optional)</span><input name="source_url" type="url" value="{{ old('source_url') }}" placeholder="https://..."></label>
            <label>Source issue time<input name="issued_at" type="datetime-local" value="{{ old('issued_at') }}" required></label>
        </div>
        <div class="field-help">The source organization is assigned by information type (PAGASA or BFP). Only verified source records can be attached to a public advisory.</div>
        <button class="button" type="submit">Submit for review</button>
    </form>
</section>
@endsection
