<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\FireIncident;
use App\Models\User;
use App\Notifications\FireIncidentVerified;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class FireIncidentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'rejected'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $incidents = FireIncident::query()
            ->with([
                'submitter:id,name,role',
                'reviewer:id,name,role',
            ])
            ->when(
                isset($filters['verification_status']),
                fn ($query) => $query->where('verification_status', $filters['verification_status']),
            )
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        return response()->json([
            'data' => $incidents->getCollection()->map(fn (FireIncident $incident): array => $this->incidentData($incident))->values(),
            'meta' => [
                'current_page' => $incidents->currentPage(),
                'last_page' => $incidents->lastPage(),
                'per_page' => $incidents->perPage(),
                'total' => $incidents->total(),
                'verified_records' => FireIncident::query()->where('verification_status', 'verified')->count(),
            ],
        ]);
    }

    public function store(Request $request, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'barangay' => ['required', 'string', 'max:120'],
            'sitio' => ['nullable', 'string', 'max:120'],
            'incident_type' => ['required', Rule::in(['structure', 'vegetation', 'grassland', 'vehicle', 'other'])],
            'cause' => ['required', Rule::in(['electrical', 'cooking', 'open_burning', 'equipment', 'lightning', 'undetermined', 'other'])],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'injuries' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'fatalities' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'property_damage_estimate' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'source_reference' => ['required', 'string', 'max:255'],
            'source_url' => ['nullable', 'url:https', 'max:2048'],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);

        $incident = DB::transaction(function () use ($validated, $request, $audit): FireIncident {
            $incident = FireIncident::create([
                ...$validated,
                'injuries' => $validated['injuries'] ?? 0,
                'fatalities' => $validated['fatalities'] ?? 0,
                'verification_status' => 'pending',
                'submitted_by' => $request->user()->id,
            ]);

            $audit->record($request->user(), 'fire_incident.submitted', $incident, [
                'occurred_at' => $incident->occurred_at?->toIso8601String(),
                'barangay' => $incident->barangay,
                'incident_type' => $incident->incident_type,
                'source_reference' => $incident->source_reference,
            ]);

            return $incident;
        });

        return response()->json([
            'message' => 'Fire incident submitted and is awaiting BFP verification.',
            'data' => $this->incidentData($incident->load(['submitter', 'reviewer'])),
        ], 201);
    }

    public function trainingData(): JsonResponse
    {
        $incidents = FireIncident::query()
            ->where('verification_status', 'verified')
            ->orderBy('occurred_at')
            ->get([
                'id',
                'occurred_at',
                'barangay',
                'sitio',
                'incident_type',
                'cause',
                'latitude',
                'longitude',
                'injuries',
                'fatalities',
                'property_damage_estimate',
                'source_reference',
            ]);

        return response()->json([
            'data' => $incidents->map(fn (FireIncident $incident): array => [
                'id' => $incident->id,
                'occurred_at' => $incident->occurred_at?->toIso8601String(),
                'barangay' => $incident->barangay,
                'sitio' => $incident->sitio,
                'incident_type' => $incident->incident_type,
                'cause' => $incident->cause,
                'latitude' => $incident->latitude,
                'longitude' => $incident->longitude,
                'injuries' => $incident->injuries,
                'fatalities' => $incident->fatalities,
                'property_damage_estimate' => $incident->property_damage_estimate,
                'source_reference' => $incident->source_reference,
                'fire_occurred' => 1,
            ])->values(),
            'meta' => [
                'verified_records' => $incidents->count(),
                'label' => 'fire_occurred=1; this export contains verified fire incidents only, not negative observations.',
            ],
        ]);
    }

    public function myVerifiedIncidents(Request $request): JsonResponse
    {
        $user = $request->user();
        $incidents = FireIncident::query()
            ->where('verification_status', 'verified')
            ->where('barangay', $user->barangay)
            ->orderByDesc('reviewed_at')
            ->limit(100)
            ->get([
                'id',
                'occurred_at',
                'barangay',
                'sitio',
                'incident_type',
                'latitude',
                'longitude',
                'reviewed_at',
            ]);
        $notifications = $user->unreadNotifications()
            ->where('type', FireIncidentVerified::class)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $incidents->map(fn (FireIncident $incident): array => [
                'id' => $incident->id,
                'occurred_at' => $incident->occurred_at?->toIso8601String(),
                'barangay' => $incident->barangay,
                'sitio' => $incident->sitio,
                'incident_type' => $incident->incident_type,
                'latitude' => $incident->latitude,
                'longitude' => $incident->longitude,
                'reviewed_at' => $incident->reviewed_at?->toIso8601String(),
            ])->values(),
            'notifications' => $notifications->map(fn ($notification): array => [
                'id' => $notification->id,
                ...$notification->data,
            ])->values(),
            'meta' => [
                'unread_count' => $notifications->count(),
            ],
        ]);
    }

    public function markFireIncidentNotificationAsRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()
            ->whereKey($notification)
            ->where('type', FireIncidentVerified::class)
            ->firstOrFail();
        $record->markAsRead();

        return response()->json(['message' => 'Past fire incident notification acknowledged.']);
    }

    public function review(Request $request, FireIncident $fireIncident, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'rejected'])],
            'review_notes' => [
                Rule::requiredIf($request->input('decision') === 'rejected'),
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        if ($fireIncident->submitted_by === $request->user()->id) {
            abort(403, 'A BFP officer must review an incident submitted by a different user.');
        }

        $incident = DB::transaction(function () use ($fireIncident, $request, $validated, $audit): FireIncident {
            $incident = FireIncident::query()->lockForUpdate()->findOrFail($fireIncident->id);
            abort_unless($incident->verification_status === 'pending', 409, 'Only pending incidents can be reviewed.');

            $incident->update([
                'verification_status' => $validated['decision'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $validated['review_notes'] ?? null,
            ]);
            $audit->record($request->user(), 'fire_incident.'.$validated['decision'], $incident, [
                'occurred_at' => $incident->occurred_at?->toIso8601String(),
                'barangay' => $incident->barangay,
                'source_reference' => $incident->source_reference,
            ]);

            if ($validated['decision'] === 'verified') {
                $residents = User::query()
                    ->where('role', UserRole::CommunityResident->value)
                    ->where('is_active', true)
                    ->where('barangay', $incident->barangay)
                    ->get();
                Notification::send($residents, new FireIncidentVerified($incident));
            }

            return $incident->load(['submitter', 'reviewer']);
        });

        return response()->json([
            'message' => 'Fire incident '.$validated['decision'].'.',
            'data' => $this->incidentData($incident),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentData(FireIncident $incident): array
    {
        return [
            'id' => $incident->id,
            'occurred_at' => $incident->occurred_at?->toIso8601String(),
            'barangay' => $incident->barangay,
            'sitio' => $incident->sitio,
            'incident_type' => $incident->incident_type,
            'cause' => $incident->cause,
            'latitude' => $incident->latitude,
            'longitude' => $incident->longitude,
            'injuries' => $incident->injuries,
            'fatalities' => $incident->fatalities,
            'property_damage_estimate' => $incident->property_damage_estimate,
            'source_reference' => $incident->source_reference,
            'source_url' => $incident->source_url,
            'details' => $incident->details,
            'verification_status' => $incident->verification_status,
            'reviewed_at' => $incident->reviewed_at?->toIso8601String(),
            'review_notes' => $incident->review_notes,
            'submitted_by' => $incident->submitter
                ? ['id' => $incident->submitter->id, 'name' => $incident->submitter->name, 'role' => $incident->submitter->role]
                : null,
            'reviewer' => $incident->reviewer
                ? ['id' => $incident->reviewer->id, 'name' => $incident->reviewer->name, 'role' => $incident->reviewer->role]
                : null,
            'created_at' => $incident->created_at?->toIso8601String(),
        ];
    }
}
