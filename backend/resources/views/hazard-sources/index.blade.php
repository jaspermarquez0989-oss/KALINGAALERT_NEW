@extends('layouts.app')

@section('title', 'Official hazard sources')

@section('content')
<section class="page-heading">
    <div><p class="eyebrow">OFFICIAL INFORMATION</p><h1>Hazard source records</h1><p class="muted">PAGASA warnings covering Bongabong are imported for staff review. Staff can also record BFP-confirmed fire information.</p></div>
    @if (in_array(auth()->user()->role, [\App\Enums\UserRole::SystemAdministrator->value, \App\Enums\UserRole::MdrrmoPersonnel->value, \App\Enums\UserRole::BfpOfficer->value], true))
        <a class="button" href="{{ route('hazard-sources.create') }}">Add source record <span aria-hidden="true">+</span></a>
    @endif
</section>
<div class="privacy-banner">Records must be checked against the issuing organization. Fire information is incident information, not a prediction of where fires may start.</div>
<section class="content-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Source / bulletin</th><th>Type</th><th>Issued</th><th>Reference</th><th>Status</th><th>Submitted by</th><th>Review</th></tr></thead>
            <tbody>
                @forelse ($sources as $source)
                    <tr>
                        <td><strong>{{ $source->title }}</strong><small>{{ $source->organization }}@if ($source->source_url) · <a href="{{ $source->source_url }}" target="_blank" rel="noopener noreferrer">Source link ↗</a>@endif</small></td>
                        <td>{{ $source->source_type === 'weather' ? 'Weather' : 'Fire information' }}</td>
                        <td>{{ $source->issued_at->format('M j, Y · g:i A') }}</td>
                        <td>{{ $source->reference ?: '—' }}</td>
                        <td><span class="status-pill {{ $source->verification_status }}">{{ $source->verification_status }}</span></td>
                        <td>{{ $source->creator?->name ?? ($source->external_id ? 'PAGASA automatic import' : '—') }}<small>{{ $source->verifier ? 'Reviewed by '.$source->verifier->name : '' }}</small></td>
                        <td>
                            <a class="text-link" href="{{ route('hazard-sources.review.show', $source) }}">{{ $source->verification_status === 'pending' ? 'Review source' : 'View source' }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><b>No source records have been submitted.</b>Staff can record information confirmed by PAGASA or BFP. Active PAGASA warnings covering Bongabong are imported for staff review.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $sources->links() }}
</section>
@endsection
