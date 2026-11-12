@extends('layouts.app')

@section('title', $alert->title)

@php
    $role = auth()->user()->role;
    $isManager = in_array($role, [\App\Enums\UserRole::SystemAdministrator->value, \App\Enums\UserRole::MdrrmoPersonnel->value], true);
    $canPublish = in_array($role, [\App\Enums\UserRole::SystemAdministrator->value, \App\Enums\UserRole::MdrrmoPersonnel->value, \App\Enums\UserRole::AlertOperator->value], true);
@endphp

@section('content')
<section class="page-heading"><div><p class="eyebrow">ALERT HISTORY · #{{ $alert->id }}</p><h1>{{ $alert->title }}</h1><p class="muted">Created by {{ $alert->creator?->name ?? 'Former user' }} · {{ $alert->created_at->format('M j, Y · g:i A') }}</p></div><a class="button button-quiet" href="{{ route('alerts.index') }}">Back to alerts</a></section>
<div class="alert-detail-header">
    <div><span class="severity-pill {{ $alert->severity }}">{{ $alert->severity }}</span> <span class="status-pill {{ $alert->status }}">{{ $alert->status }}</span></div>
    <div class="page-actions">
        @if (in_array($alert->status, [\App\Models\Alert::STATUS_DRAFT, \App\Models\Alert::STATUS_APPROVED], true))
            <a class="button button-quiet" href="{{ route('alerts.edit', $alert) }}">Edit draft</a>
        @endif
        @if ($isManager && $alert->status === \App\Models\Alert::STATUS_DRAFT && $alert->created_by !== auth()->id())
            <form method="POST" action="{{ route('alerts.approve', $alert) }}">@csrf @method('PATCH')<button class="button" type="submit">Approve alert</button></form>
        @endif
        @if ($canPublish && $alert->status === \App\Models\Alert::STATUS_APPROVED && $alert->approved_by !== auth()->id())
            <form method="POST" action="{{ route('alerts.publish', $alert) }}">@csrf @method('PATCH')<button class="button" type="submit">Publish and activate now</button></form>
        @endif
        @if ($alert->status === \App\Models\Alert::STATUS_APPROVED && $alert->approved_by === auth()->id())
            <p class="muted">You approved this alert. Sign in as a different authorized staff member to publish it to matched residents.</p>
        @endif
        @if ($isManager && $alert->status === \App\Models\Alert::STATUS_PUBLISHED)
            <form method="POST" action="{{ route('alerts.revise', $alert) }}">@csrf<button class="button button-quiet" type="submit">Prepare a revision</button></form>
        @endif
    </div>
</div>
<section class="alert-detail-grid">
    <article class="content-card">
        <div class="detail-block"><span class="detail-label">PUBLIC ADVISORY</span><p>{{ $alert->message }}</p></div>
        <div class="detail-block"><span class="detail-label">SAFETY INSTRUCTIONS</span><p>{{ $alert->safety_instructions }}</p></div>
        <div class="detail-block"><span class="detail-label">OFFICIAL SOURCE</span><h2>{{ $alert->source->organization }} · {{ $alert->source->title }}</h2><p>{{ $alert->source->details }}</p>
            <div class="source-detail">
                <div><span class="detail-label">SOURCE ISSUE TIME</span><strong>{{ $alert->source->issued_at->format('M j, Y · g:i A') }}</strong></div>
                <div><span class="detail-label">REFERENCE</span><strong>{{ $alert->source->reference ?: 'Not provided' }}</strong></div>
                <div><span class="detail-label">VERIFICATION</span><strong>Verified by {{ $alert->source->verifier?->name ?? 'authorized staff' }}</strong></div>
                @if ($alert->source->source_url)<div><span class="detail-label">ORIGINAL SOURCE</span><strong><a href="{{ $alert->source->source_url }}" target="_blank" rel="noopener noreferrer">Open official source ↗</a></strong></div>@endif
            </div>
        </div>
        <div class="source-detail">
            <div><span class="detail-label">APPROVED BY</span><strong>{{ $alert->approver?->name ?? 'Not approved' }}</strong></div>
            <div><span class="detail-label">PUBLISHED</span><strong>{{ $alert->published_at?->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Not published' }}</strong></div>
            <div><span class="detail-label">START (PHILIPPINE TIME)</span><strong>{{ $alert->starts_at?->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Immediately' }}</strong></div>
            <div><span class="detail-label">EXPIRES (PHILIPPINE TIME)</span><strong>{{ $alert->ends_at?->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'No expiry set' }}</strong></div>
        </div>
        @if ($alert->supersedes)<p class="field-help">Revision of alert #{{ $alert->supersedes->id }}. The prior alert remains active until this revision is published.</p>@endif
    </article>
    <div>
        <article class="panel">
            <div class="panel-head"><div><h2>Selected coverage</h2><p>Coverage stored for this alert</p></div></div>
            <div class="map-frame"><div id="alert-map" class="risk-map" data-risk-map></div></div>
            <script id="risk-map-data" type="application/json">@json($mapAreas)</script>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Selected area</th><th>Location</th></tr></thead><tbody>
                @foreach ($alert->riskAreas as $area)
                    @php
                        $snapshot = $area->pivot->coverage_snapshot;
                        if (is_string($snapshot)) $snapshot = json_decode($snapshot, true);
                        $areaSite = $snapshot['sitio'] ?? $area->sitio;
                    @endphp
                    <tr><td><strong>{{ $snapshot['name'] ?? $area->name }}</strong><small>{{ $snapshot['classification'] ?? $area->classification }}</small></td><td>{{ $areaSite ? $areaSite.', ' : '' }}{{ $snapshot['barangay'] ?? $area->barangay }}</td></tr>
                @endforeach
            </tbody></table></div>
            <p class="map-footnote">Household coordinates are not displayed.</p>
        </article>
        <article class="content-card recipient-card"><span class="detail-label">MATCHED WEB RECIPIENTS</span><strong class="recipient-count">{{ $recipientCount }}</strong><p>Individual resident details remain private. Delivery here means the alert is available in the matched account’s web inbox; no SMS or push channel is connected.</p></article>
        @if ($canPublish && in_array($alert->status, [\App\Models\Alert::STATUS_DRAFT, \App\Models\Alert::STATUS_APPROVED, \App\Models\Alert::STATUS_PUBLISHED], true))
            <article class="content-card close-card">
                <span class="detail-label">CLOSE THIS ALERT</span><form method="POST" action="{{ route('alerts.close', $alert) }}" class="close-alert-form">
                    @csrf @method('PATCH')
                    <label class="filter-label">Action<select name="action"><option value="cancelled">Cancel</option><option value="expired">Expire</option></select></label>
                    <label class="filter-label">Reason<input name="reason" required maxlength="500" placeholder="Reason for closing"></label>
                    <button class="button button-danger button-small-inline" type="submit">Close</button>
                </form>
            </article>
        @endif
    </div>
</section>
@endsection
