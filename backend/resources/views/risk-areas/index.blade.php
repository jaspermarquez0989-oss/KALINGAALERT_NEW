@extends('layouts.app')

@section('title', 'Risk areas')

@section('content')
<section class="page-heading">
    <div><p class="eyebrow">MDRRMO COVERAGE</p><h1>Vulnerable areas</h1><p class="muted">Map, classify, and maintain locally verified risk-area boundaries.</p></div>
    @if (in_array(auth()->user()->role, [\App\Enums\UserRole::SystemAdministrator->value, \App\Enums\UserRole::MdrrmoPersonnel->value], true))
        <a class="button" href="{{ route('risk-areas.create') }}">Add risk area <span aria-hidden="true">+</span></a>
    @endif
</section>
<div class="privacy-banner">Risk-area boundaries are shown on maps. Resident names, home addresses, and household coordinates are never displayed here.</div>
<section class="content-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Area</th><th>Location</th><th>Classification</th><th>Status</th><th>Updated by</th><th>Actions</th></tr></thead>
            <tbody>
                @forelse ($areas as $area)
                    <tr>
                        <td><strong>{{ $area->name }}</strong><small>{{ $area->boundary['type'] ?? 'Polygon' }} boundary</small></td>
                        <td>{{ $area->sitio ? $area->sitio.', ' : '' }}{{ $area->barangay }}</td>
                        <td>{{ $area->classification }}</td>
                        <td><span class="status-pill {{ $area->is_active ? 'active' : 'inactive' }}">{{ $area->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td>{{ $area->creator?->name ?? '—' }}</td>
                        <td>
                            @if (in_array(auth()->user()->role, [\App\Enums\UserRole::SystemAdministrator->value, \App\Enums\UserRole::MdrrmoPersonnel->value], true))
                                <div class="table-actions"><a class="text-link" href="{{ route('risk-areas.edit', $area) }}">Edit</a>
                                    <form method="POST" action="{{ route('risk-areas.toggle', $area) }}">@csrf @method('PATCH')<button class="button button-small-inline button-muted" type="submit">{{ $area->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                                </div>
                            @else
                                <span class="muted">Read only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><b>No vulnerable areas have been recorded.</b>Only authorized MDRRMO users can add an area.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $areas->links() }}
</section>
@endsection
