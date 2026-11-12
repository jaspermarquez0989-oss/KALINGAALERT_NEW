@extends('layouts.app')

@section('title', 'Audit trail')

@section('content')
<section class="page-heading"><div><p class="eyebrow">SYSTEM ADMINISTRATION</p><h1>Audit trail</h1><p class="muted">Significant changes to users, source records, risk areas, and alerts.</p></div><a class="button button-quiet" href="{{ route('staff.index') }}">Staff accounts</a></section>
<section class="content-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Record</th><th>Details</th></tr></thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td>{{ $entry->created_at->format('M j, Y · g:i:s A') }}</td>
                        <td>{{ $entry->actor?->name ?? 'Former user' }}</td>
                        <td><strong>{{ str_replace('.', ' · ', $entry->action) }}</strong></td>
                        <td>{{ class_basename($entry->subject_type) }}{{ $entry->subject_id ? ' #'.$entry->subject_id : '' }}</td>
                        <td><code>{{ json_encode($entry->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><b>No audit events recorded.</b>System activity will appear here as staff manage coverage and alerts.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $entries->links() }}
</section>
@endsection
