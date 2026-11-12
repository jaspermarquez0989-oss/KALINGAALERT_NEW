@extends('layouts.app')

@section('title', 'Staff accounts')

@section('content')
<section class="page-heading"><div><p class="eyebrow">SYSTEM ADMINISTRATION</p><h1>Authorized staff</h1><p class="muted">Manage staff roles and account access. Resident accounts cannot be promoted through registration.</p></div><a class="button" href="{{ route('staff.create') }}">Create staff account <span aria-hidden="true">+</span></a></section>
<div class="privacy-banner">Assign only the permissions each staff member needs. An active system administrator must always remain available.</div>
<section class="content-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Account access</th></tr></thead>
            <tbody>
                @forelse ($staff as $member)
                    <tr>
                        <td><strong>{{ $member->name }}</strong>@if ($member->id === auth()->id())<small>Your account</small>@endif</td>
                        <td>{{ $member->email }}</td>
                        <td>
                            <form method="POST" action="{{ route('staff.role', $member) }}" class="inline-role-form">
                                @csrf @method('PATCH')
                                <select name="role" aria-label="Role for {{ $member->name }}">
                                    @foreach ($roles as $value => $label)
                                        @if ($value !== \App\Enums\UserRole::CommunityResident->value)
                                            <option value="{{ $value }}" @selected($member->role === $value)>{{ $label }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <button class="button button-small-inline button-muted" type="submit">Update</button>
                            </form>
                        </td>
                        <td><span class="status-pill {{ $member->is_active ? 'active' : 'inactive' }}">{{ $member->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td>{{ $member->created_at->format('M j, Y') }}</td>
                        <td>
                            @if ($member->id !== auth()->id())
                                <form method="POST" action="{{ route('staff.active', $member) }}">@csrf @method('PATCH')<button class="button button-small-inline {{ $member->is_active ? 'button-danger' : 'button-muted' }}" type="submit">{{ $member->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                            @else
                                <span class="muted">Current account</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><b>No authorized staff accounts.</b>Create individual staff accounts to manage KalingaAlert.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $staff->links() }}
</section>
@endsection
