@extends('layouts.app')

@section('title', $area->exists ? 'Edit risk area' : 'Add risk area')

@section('content')
<section class="page-heading"><div><p class="eyebrow">MDRRMO COVERAGE</p><h1>{{ $area->exists ? 'Update vulnerable area' : 'Add vulnerable area' }}</h1><p class="muted">Only use boundaries and classifications verified by authorized municipal personnel.</p></div><a class="button button-quiet" href="{{ route('risk-areas.index') }}">Back to areas</a></section>
<section class="content-card">
    <form method="POST" action="{{ $area->exists ? route('risk-areas.update', $area) : route('risk-areas.store') }}" class="form-stack">
        @csrf
        @if ($area->exists) @method('PUT') @endif
        <div class="form-grid">
            <label>Area name<input name="name" value="{{ old('name', $area->name) }}" required maxlength="150" placeholder="e.g. Riverside low-lying zone"></label>
            <label>Classification<select name="classification" required>
                @foreach (['Flood-prone', 'Landslide-prone', 'Storm-surge-prone', 'Fire-prone', 'Other verified risk'] as $classification)
                    <option value="{{ $classification }}" @selected(old('classification', $area->classification) === $classification)>{{ $classification }}</option>
                @endforeach
            </select></label>
            <label>Barangay<input name="barangay" value="{{ old('barangay', $area->barangay) }}" required maxlength="120"></label>
            <label>Sitio / purok <span class="optional">(optional)</span><input name="sitio" value="{{ old('sitio', $area->sitio) }}" maxlength="120"></label>
        </div>
        <div class="form-section-title">Draw the verified map boundary</div>
        <div class="boundary-help"><span>Click “Start drawing,” select at least three points around the mapped coverage, then finish the boundary.</span><div id="boundary-controls"><button class="button button-small-inline button-muted" type="button" id="start-drawing">Start drawing</button> <button class="button button-small-inline" type="button" id="finish-drawing" disabled>Finish boundary</button></div></div>
        <div class="boundary-editor"><div class="risk-map" data-risk-map data-map-mode="editor"></div></div>
        <textarea id="boundary" name="boundary" hidden required>{{ old('boundary', $area->boundary ? json_encode($area->boundary, JSON_UNESCAPED_SLASHES) : '') }}</textarea>
        <div class="field-help">The map is restricted to Bongabong municipality. Boundary source: geoBoundaries (NAMRIA/PSA/OCHA), CC BY 3.0 IGO. Risk polygons are stored in GeoJSON longitude/latitude coordinates and used to match optional resident coordinates privately.</div>
        <label class="check-row"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $area->exists ? $area->is_active : true))> Active and available for alert coverage</label>
        <div class="page-actions"><button class="button" type="submit">{{ $area->exists ? 'Save area changes' : 'Create risk area' }}</button><a class="button button-quiet" href="{{ route('risk-areas.index') }}">Cancel</a></div>
    </form>
</section>
@endsection
