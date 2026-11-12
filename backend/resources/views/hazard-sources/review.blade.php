@extends('layouts.app')

@section('title', 'Review source')

@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">OFFICIAL INFORMATION · SOURCE REVIEW</p>
        <h1>{{ $source->title }}</h1>
        <p class="muted">{{ $source->organization }} · {{ $source->source_type === 'weather' ? 'Weather' : 'Fire information' }}</p>
    </div>
    <a class="button button-quiet" href="{{ route('hazard-sources.index') }}">Back to sources</a>
</section>

<div class="alert-detail-header">
    <div><span class="status-pill {{ $source->verification_status }}">{{ $source->verification_status }}</span></div>
    @if ($source->verification_status === 'pending' && $source->created_by !== auth()->id())
        <div class="page-actions">
            <form method="POST" action="{{ route('hazard-sources.review', $source) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="decision" value="verified">
                <button class="button" type="submit">Verify source</button>
            </form>
            <form method="POST" action="{{ route('hazard-sources.review', $source) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="decision" value="rejected">
                <button class="button button-danger" type="submit">Reject source</button>
            </form>
        </div>
    @elseif ($source->verification_status === 'pending')
        <p class="muted">You submitted this record. Another staff member must review it.</p>
    @endif
</div>

<section class="content-card">
    <div class="detail-block">
        <span class="detail-label">SOURCE DETAILS</span>
        <p>{{ $source->details }}</p>
    </div>
    <div class="source-detail">
        <div><span class="detail-label">ISSUE TIME</span><strong>{{ $source->issued_at->format('M j, Y · g:i A') }}</strong></div>
        <div><span class="detail-label">REFERENCE</span><strong>{{ $source->reference ?: 'Not provided' }}</strong></div>
        <div><span class="detail-label">SUBMITTED BY</span><strong>{{ $source->creator?->name ?? ($source->external_id ? 'PAGASA automatic import' : 'Unknown') }}</strong></div>
        @if ($source->external_id)
            <div><span class="detail-label">RECORD ORIGIN</span><strong>Automatically imported from PAGASA</strong></div>
        @endif
        @if ($source->source_url)
            <div><span class="detail-label">ORIGINAL SOURCE</span><strong><a href="{{ $source->source_url }}" target="_blank" rel="noopener noreferrer">Open official source ↗</a></strong></div>
        @endif
        @if ($source->verifier)
            <div><span class="detail-label">{{ ucfirst($source->verification_status).' by' }}</span><strong>{{ $source->verifier->name }} · {{ $source->verified_at?->format('M j, Y · g:i A') }}</strong></div>
        @endif
    </div>
</section>
@endsection
