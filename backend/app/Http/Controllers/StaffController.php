<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $staff = User::query()->whereIn('role', UserRole::staffValues())->orderBy('name');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $staff->when(
            isset($filters['search']),
            fn (Builder $query) => $query->where(
                fn (Builder $searchQuery) => $searchQuery
                    ->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('email', 'like', '%'.$filters['search'].'%'),
            ),
        );

        return UserResource::collection($staff->paginate($filters['per_page'] ?? 15)->withQueryString());
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(UserRole::staffValues())],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        $user = DB::transaction(function () use ($validated, $request, $audit): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $validated['role'],
                'password' => $validated['password'],
                'is_active' => true,
            ]);
            $audit->record($request->user(), 'staff.created', $user, ['role' => $user->role]);

            return $user;
        });

        if ($request->routeIs('api.v1.*')) {
            return response()->json([
                'message' => 'Staff account created.',
                'data' => UserResource::make($user),
            ], 201);
        }

        return redirect()->route('staff.index')->with('status', 'Staff account created. Share the temporary password through a secure channel.');
    }

    public function updateRole(Request $request, User $user, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        abort_unless(in_array($user->role, UserRole::staffValues(), true), 404);
        $validated = $request->validate([
            'role' => ['required', Rule::in(UserRole::staffValues())],
        ]);

        if (
            $user->role === UserRole::SystemAdministrator->value
            && $validated['role'] !== UserRole::SystemAdministrator->value
            && $user->is_active
            && User::query()
                ->where('role', UserRole::SystemAdministrator->value)
                ->where('is_active', true)
                ->count() <= 1
        ) {
            if ($request->routeIs('api.v1.*')) {
                return response()->json([
                    'message' => 'At least one active system administrator must remain.',
                    'errors' => ['role' => ['At least one active system administrator must remain.']],
                ], 422);
            }

            return back()->withErrors(['role' => 'At least one active system administrator must remain.']);
        }

        DB::transaction(function () use ($user, $validated, $request, $audit): void {
            $previousRole = $user->role;
            $user->update(['role' => $validated['role']]);
            $audit->record($request->user(), 'staff.role_changed', $user, [
                'previous_role' => $previousRole,
                'role' => $user->role,
            ]);
        });

        if ($request->routeIs('api.v1.*')) {
            return response()->json([
                'message' => 'Staff role updated.',
                'data' => UserResource::make($user->fresh()),
            ]);
        }

        return back()->with('status', 'Staff role updated.');
    }

    public function toggleActive(Request $request, User $user, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        abort_unless(in_array($user->role, UserRole::staffValues(), true), 404);

        if ($user->id === $request->user()->id) {
            if ($request->routeIs('api.v1.*')) {
                return response()->json([
                    'message' => 'You cannot deactivate your own account.',
                    'errors' => ['account' => ['You cannot deactivate your own account.']],
                ], 422);
            }

            return back()->withErrors(['account' => 'You cannot deactivate your own account.']);
        }

        if (
            $user->role === UserRole::SystemAdministrator->value
            && $user->is_active
            && User::query()
                ->where('role', UserRole::SystemAdministrator->value)
                ->where('is_active', true)
                ->count() <= 1
        ) {
            if ($request->routeIs('api.v1.*')) {
                return response()->json([
                    'message' => 'At least one active system administrator must remain.',
                    'errors' => ['account' => ['At least one active system administrator must remain.']],
                ], 422);
            }

            return back()->withErrors(['account' => 'At least one active system administrator must remain.']);
        }

        DB::transaction(function () use ($user, $request, $audit): void {
            $user->update(['is_active' => ! $user->is_active]);
            $audit->record($request->user(), $user->is_active ? 'staff.activated' : 'staff.deactivated', $user);
        });

        if ($request->routeIs('api.v1.*')) {
            return response()->json([
                'message' => 'Staff account status updated.',
                'data' => UserResource::make($user->fresh()),
            ]);
        }

        return back()->with('status', 'Staff account status updated.');
    }

    public function audit(Request $request): JsonResponse
    {
        $entries = AuditLog::query()
            ->where('action', '!=', 'system.viewed')
            ->with('actor')
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => $entries->getCollection()->map(fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'subject_type' => $entry->subject_type,
                'subject_id' => $entry->subject_id,
                'metadata' => $entry->metadata,
                'created_at' => $entry->created_at?->toIso8601String(),
                'actor' => UserResource::make($entry->actor)->resolve($request),
            ])->values(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }
}
