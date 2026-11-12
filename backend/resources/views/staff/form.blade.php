@extends('layouts.app')

@section('title', 'Create staff account')

@section('content')
<section class="page-heading"><div><p class="eyebrow">SYSTEM ADMINISTRATION</p><h1>Create staff account</h1><p class="muted">Only a system administrator can create or assign staff roles.</p></div><a class="button button-quiet" href="{{ route('staff.index') }}">Back to staff</a></section>
<section class="content-card narrow-card">
    <form method="POST" action="{{ route('staff.store') }}" class="form-stack">
        @csrf
        <label>Full name<input name="name" value="{{ old('name') }}" maxlength="120" required></label>
        <label>Email address<input name="email" type="email" value="{{ old('email') }}" maxlength="255" required></label>
        <label>Staff role<select name="role" required>
            @foreach ($roles as $value => $label)
                @if ($value !== \App\Enums\UserRole::CommunityResident->value)
                    <option value="{{ $value }}" @selected(old('role') === $value)>{{ $label }}</option>
                @endif
            @endforeach
        </select></label>
        <div class="field-help">System administrator: staff, roles, settings and audit trail. MDRRMO: risk areas, source review, alert approval. BFP officer: submit fire information. Alert operator: prepare and publish approved alerts.</div>
        <div class="form-grid">
            <label>Temporary password<input name="password" type="password" autocomplete="new-password" minlength="12" required><small>At least 12 characters with letters and numbers.</small></label>
            <label>Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" required></label>
        </div>
        <button class="button" type="submit">Create authorized account</button>
    </form>
</section>
@endsection
