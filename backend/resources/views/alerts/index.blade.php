@extends('layouts.app')

@section('title', 'Alert workspace')

@section('content')
<section class="page-heading"><div><p class="eyebrow">TARGETED ADVISORIES</p><h1>Alert workspace</h1><p class="muted">Draft, approve, and publish alerts to the selected matched resident locations.</p></div><a class="button" href="{{ route('alerts.create') }}">Prepare an alert <span aria-hidden="true">+</span></a></section>
<div class="privacy-banner">Publishing creates in-app web alerts for matched resident accounts only. No SMS, push notifications, emergency reporting, or rescue dispatch is enabled.</div>
<section class="content-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Advisory</th><th>Hazard source</th><th>Coverage</th><th>Status</th><th>Recipients</th><th>Created</th><th></th></tr></thead>
            <tbody>
                @forelse ($alerts as $alert)
                    <tr>
                        <td><strong>{{ $alert->title }}</strong><small><span class="severity-pill {{ $alert->severity }}">{{ $alert->severity }}</span></small></td>
                        <td>{{ $alert->source->organization }}<small>{{ $alert->source->title }}</small></td>
                        <td>{{ $alert->riskAreas()->count() }} selected area(s)</td>
                        <td><span class="status-pill {{ $alert->status }}">{{ $alert->status }}</span></td>
                        <td>{{ $alert->recipients_count }}</td>
                        <td>{{ $alert->created_at->format('M j, Y') }}</td>
                        <td><a class="text-link" href="{{ route('alerts.show', $alert) }}">Open →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><b>No alerts have been prepared.</b>Verify a source record and map affected coverage before drafting an advisory.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $alerts->links() }}
</section>
@endsection
