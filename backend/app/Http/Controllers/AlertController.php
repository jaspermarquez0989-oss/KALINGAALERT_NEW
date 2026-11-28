<?php

namespace App\Http\Controllers;

use App\Enums\HazardType;
use App\Enums\UserRole;
use App\Http\Resources\Api\V1\AlertRecipientResource;
use App\Http\Resources\Api\V1\AlertResource;
use App\Models\Alert;
use App\Models\AlertRecipient;
use App\Models\RiskArea;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\RecipientMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AlertController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Alert::query()
            ->with(['source', 'creator', 'approver', 'riskAreas'])
            ->withCount('recipients');

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([
                Alert::STATUS_DRAFT,
                Alert::STATUS_APPROVED,
                Alert::STATUS_PUBLISHED,
                Alert::STATUS_CANCELLED,
                Alert::STATUS_EXPIRED,
            ])],
            'severity' => ['sometimes', Rule::in(['advisory', 'watch', 'warning', 'emergency'])],
            'risk_area_id' => ['sometimes', 'integer', 'exists:risk_areas,id'],
            'sort' => ['sometimes', Rule::in(['created_at', 'starts_at', 'ends_at', 'severity', 'title'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query
            ->when(isset($filters['status']), fn (Builder $builder) => $builder->where('status', $filters['status']))
            ->when(isset($filters['severity']), fn (Builder $builder) => $builder->where('severity', $filters['severity']))
            ->when(
                isset($filters['risk_area_id']),
                fn (Builder $builder) => $builder->whereHas(
                    'riskAreas',
                    fn (Builder $areas) => $areas->whereKey($filters['risk_area_id']),
                ),
            )
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderByDesc('id');

        return AlertResource::collection($query->paginate($filters['per_page'] ?? 15)->withQueryString());
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        $validated = $this->validatedAlert($request);

        $alert = DB::transaction(function () use ($validated, $request, $audit): Alert {
            $alert = Alert::create([
                'hazard_source_id' => $validated['hazard_source_id'],
                'title' => $validated['title'],
                'message' => $validated['message'],
                'safety_instructions' => $validated['safety_instructions'],
                'severity' => $validated['severity'],
                'intensity' => $validated['intensity'] ?? 3,
                'status' => Alert::STATUS_DRAFT,
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $alert->riskAreas()->sync($validated['risk_area_ids']);

            $audit->record($request->user(), 'alert.drafted', $alert, [
                'title' => $alert->title,
                'severity' => $alert->severity,
                'intensity' => $alert->intensity,
                'risk_area_ids' => $validated['risk_area_ids'],
            ]);

            return $alert;
        });

        if ($this->isApiRequest($request)) {
            return response()->json([
                'data' => AlertResource::make($alert->load(['source', 'riskAreas', 'creator', 'approver'])),
            ], 201);
        }

        return redirect()->route('alerts.show', $alert)->with('status', 'Alert submitted for independent approval. It remains unpublished until approved and published.');
    }

    public function show(Alert $alert): AlertResource
    {
        $alert->load(['source', 'riskAreas', 'creator', 'approver', 'supersedes']);

        return AlertResource::make($alert);
    }

    public function update(Request $request, Alert $alert, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        abort_unless(in_array($alert->status, [Alert::STATUS_DRAFT, Alert::STATUS_APPROVED], true), 409);

        if ($request->isMethod('PATCH')) {
            $alert->load('riskAreas');
            $request->merge([
                'hazard_source_id' => $request->input('hazard_source_id', $alert->hazard_source_id),
                'title' => $request->input('title', $alert->title),
                'message' => $request->input('message', $alert->message),
                'safety_instructions' => $request->input('safety_instructions', $alert->safety_instructions),
                'severity' => $request->input('severity', $alert->severity),
                'intensity' => $request->input('intensity', $alert->intensity),
                'starts_at' => $request->input('starts_at', $alert->starts_at?->toIso8601String()),
                'ends_at' => $request->input('ends_at', $alert->ends_at?->toIso8601String()),
                'risk_area_ids' => $request->input('risk_area_ids', $alert->riskAreas->modelKeys()),
            ]);
        }

        $validated = $this->validatedAlert($request);
        $wasApproved = $alert->status === Alert::STATUS_APPROVED;

        DB::transaction(function () use ($alert, $validated, $request, $audit, $wasApproved): void {
            $alert->update([
                'hazard_source_id' => $validated['hazard_source_id'],
                'title' => $validated['title'],
                'message' => $validated['message'],
                'safety_instructions' => $validated['safety_instructions'],
                'severity' => $validated['severity'],
                'intensity' => $validated['intensity'] ?? $alert->intensity,
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'] ?? null,
                'status' => Alert::STATUS_DRAFT,
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $alert->riskAreas()->sync($validated['risk_area_ids']);
            $audit->record($request->user(), 'alert.revised', $alert, [
                'title' => $alert->title,
                'severity' => $alert->severity,
                'intensity' => $alert->intensity,
                'approval_reset' => $wasApproved,
                'risk_area_ids' => $validated['risk_area_ids'],
            ]);
        });

        if ($this->isApiRequest($request)) {
            return response()->json([
                'message' => 'Alert revised and returned to draft for approval.',
                'data' => AlertResource::make($alert->fresh()->load(['source', 'riskAreas', 'creator', 'approver'])),
            ]);
        }

        return redirect()->route('alerts.show', $alert)->with('status', 'Alert revised and returned to draft for approval.');
    }

    public function approve(Request $request, Alert $alert, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        if ($alert->created_by === $request->user()->id) {
            if ($this->isApiRequest($request)) {
                return response()->json(['message' => 'A different authorized user must approve this alert.'], 403);
            }

            return back()->withErrors(['approval' => 'A different authorized user must approve this alert.']);
        }

        if ($alert->status !== Alert::STATUS_DRAFT) {
            if ($this->isApiRequest($request)) {
                return response()->json(['message' => 'Only draft alerts can be approved.'], 409);
            }

            return back()->withErrors(['approval' => 'Only draft alerts can be approved.']);
        }

        if (! $alert->source()->where('verification_status', 'verified')->exists()) {
            if ($this->isApiRequest($request)) {
                return response()->json(['message' => 'The official source must be verified before approval.'], 409);
            }

            return back()->withErrors(['approval' => 'The official source must be verified before approval.']);
        }

        if (! $alert->riskAreas()->where('is_active', true)->exists()) {
            if ($this->isApiRequest($request)) {
                return response()->json(['message' => 'At least one selected vulnerable area must still be active.'], 409);
            }

            return back()->withErrors(['approval' => 'At least one selected vulnerable area must still be active.']);
        }

        DB::transaction(function () use ($request, $alert, $audit): void {
            $alert->update([
                'status' => Alert::STATUS_APPROVED,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
            $audit->record($request->user(), 'alert.approved', $alert, ['title' => $alert->title]);
        });

        if ($this->isApiRequest($request)) {
            return response()->json([
                'message' => 'Alert approved. An authorized operator can now publish it to all residents.',
                'data' => AlertResource::make($alert->fresh()->load(['source', 'riskAreas', 'creator', 'approver'])),
            ]);
        }

        return back()->with('status', 'Alert approved. An authorized operator can now publish it to all residents.');
    }

    public function publish(
        Request $request,
        Alert $alert,
        RecipientMatcher $matcher,
        AuditRecorder $audit,
    ): RedirectResponse|JsonResponse {
        $recipientCount = DB::transaction(function () use ($request, $alert, $matcher, $audit): int {
            $lockedAlert = Alert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            $canPublishImmediately = in_array($request->user()->role, [
                UserRole::SystemAdministrator->value,
                UserRole::MdrrmoPersonnel->value,
            ], true);
            $publishingDraft = $lockedAlert->status === Alert::STATUS_DRAFT && $canPublishImmediately;

            if ($lockedAlert->status !== Alert::STATUS_APPROVED && ! $publishingDraft) {
                abort(409, 'Only approved alerts can be published unless you are a system administrator or MDRRMO personnel publishing a draft immediately.');
            }

            if (! $publishingDraft && $lockedAlert->approved_by === $request->user()->id && ! $canPublishImmediately) {
                abort(403, 'The approver cannot publish their own approval.');
            }

            if ($publishingDraft && ! $lockedAlert->source()->where('verification_status', 'verified')->exists()) {
                abort(409, 'The official source must be verified before an alert can be published.');
            }

            $areas = $lockedAlert->riskAreas()->where('is_active', true)->get();

            if ($areas->isEmpty()) {
                abort(409, 'At least one selected vulnerable area must still be active.');
            }

            $residents = $matcher->allActiveResidents();
            $now = now();

            $lockedAlert->update([
                'status' => Alert::STATUS_PUBLISHED,
                'starts_at' => $now,
                'published_at' => $now,
            ]);

            $lockedAlert->riskAreas()->sync($areas->mapWithKeys(fn (RiskArea $area): array => [
                $area->id => [
                    'coverage_snapshot' => [
                        'name' => $area->name,
                        'barangay' => $area->barangay,
                        'sitio' => $area->sitio,
                        'classification' => $area->classification,
                        'boundary' => $area->boundary,
                        'latitude' => $area->latitude,
                        'longitude' => $area->longitude,
                    ],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ])->all());

            $lockedAlert->recipients()->createMany($residents->map(fn (User $resident): array => [
                'user_id' => $resident->id,
                'delivered_at' => $now,
            ])->all());

            if ($lockedAlert->supersedes_id !== null) {
                $previousAlert = Alert::query()->whereKey($lockedAlert->supersedes_id)->lockForUpdate()->firstOrFail();
                $previousAlert->update(['status' => Alert::STATUS_EXPIRED, 'ends_at' => $now]);
                $audit->record($request->user(), 'alert.replaced', $previousAlert, [
                    'replacement_alert_id' => $lockedAlert->id,
                ]);
            }

            $audit->record($request->user(), 'alert.published', $lockedAlert, [
                'recipient_count' => $residents->count(),
                'delivery_scope' => 'all_active_residents',
                'approval_bypassed' => $publishingDraft,
                'risk_area_ids' => $areas->modelKeys(),
                'starts_at' => $now->toIso8601String(),
            ]);

            return $residents->count();
        });

        $message = $recipientCount === 0
            ? 'Alert published. No active resident accounts are currently registered.'
            : "Alert published to {$recipientCount} resident account(s).";

        if ($this->isApiRequest($request)) {
            return response()->json([
                'message' => $message,
                'data' => AlertResource::make($alert->fresh()->load(['source', 'riskAreas', 'creator', 'approver'])),
            ]);
        }

        return redirect()->route('alerts.show', $alert)->with('status', $message);
    }

    public function revise(Request $request, Alert $alert, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        abort_unless($alert->status === Alert::STATUS_PUBLISHED, 409);
        $alert->load('riskAreas');

        $revision = DB::transaction(function () use ($alert, $request, $audit): Alert {
            $revision = Alert::create([
                'hazard_source_id' => $alert->hazard_source_id,
                'title' => $alert->title.' (Revised)',
                'message' => $alert->message,
                'safety_instructions' => $alert->safety_instructions,
                'severity' => $alert->severity,
                'intensity' => $alert->intensity,
                'status' => Alert::STATUS_DRAFT,
                'starts_at' => now(),
                'ends_at' => $alert->ends_at?->isFuture() ? $alert->ends_at : null,
                'created_by' => $request->user()->id,
                'supersedes_id' => $alert->id,
            ]);
            $revision->riskAreas()->sync($alert->riskAreas->modelKeys());
            $audit->record($request->user(), 'alert.revision_started', $revision, [
                'revises_alert_id' => $alert->id,
                'title' => $revision->title,
            ]);

            return $revision;
        });

        if ($this->isApiRequest($request)) {
            return response()->json([
                'message' => 'A replacement draft was created. The current alert remains active until the replacement is published.',
                'data' => AlertResource::make($revision->load(['source', 'riskAreas', 'creator', 'approver'])),
            ], 201);
        }

        return redirect()->route('alerts.edit', $revision)->with('status', 'A replacement draft was created. The current alert remains active until the replacement is published.');
    }

    public function close(Request $request, Alert $alert, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['cancelled', 'expired'])],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if (in_array($alert->status, [Alert::STATUS_CANCELLED, Alert::STATUS_EXPIRED], true)) {
            if ($this->isApiRequest($request)) {
                return response()->json(['message' => 'This alert is already closed.'], 409);
            }

            return back()->withErrors(['action' => 'This alert is already closed.']);
        }

        DB::transaction(function () use ($request, $alert, $validated, $audit): void {
            $alert->update([
                'status' => $validated['action'],
                'ends_at' => now(),
            ]);
            $audit->record($request->user(), 'alert.'.$validated['action'], $alert, [
                'reason' => $validated['reason'],
            ]);
        });

        if ($this->isApiRequest($request)) {
            return response()->json([
                'message' => 'Alert '.$validated['action'].'.',
                'data' => AlertResource::make($alert->fresh()->load(['source', 'riskAreas', 'creator', 'approver'])),
            ]);
        }

        return back()->with('status', 'Alert '.$validated['action'].'.');
    }

    public function myAlerts(Request $request, RecipientMatcher $matcher): AnonymousResourceCollection
    {
        $matcher->deliverActiveAlertsToResident($request->user());

        $recipients = AlertRecipient::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('alert', fn (Builder $query) => $query->currentlyActive())
            ->with('alert.source', 'alert.riskAreas')
            ->orderByDesc('delivered_at')
            ->paginate(12);

        return AlertRecipientResource::collection($recipients);
    }

    public function residentStatus(Request $request, RecipientMatcher $matcher): JsonResponse
    {
        $matcher->deliverActiveAlertsToResident($request->user());

        $activeRecipients = AlertRecipient::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('alert', fn (Builder $query) => $query->currentlyActive());
        $unreadRecipients = (clone $activeRecipients)->whereNull('read_at');

        return response()->json([
            'active_alert_count' => (clone $activeRecipients)->count(),
            'unread_count' => (clone $unreadRecipients)->count(),
            'alerts' => $unreadRecipients
                ->with('alert:id,title')
                ->orderByDesc('delivered_at')
                ->limit(20)
                ->get()
                ->map(fn (AlertRecipient $recipient): array => [
                    'id' => $recipient->alert_id,
                    'title' => $recipient->alert->title,
                    'url' => $this->isApiRequest($request)
                        ? route('api.v1.my-alerts.show', $recipient->alert_id)
                        : route('resident.alerts.show', $recipient->alert_id),
                ])
                ->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function viewForResident(Request $request, Alert $alert): AlertResource|RedirectResponse
    {
        $recipient = AlertRecipient::query()
            ->where('alert_id', $alert->id)
            ->where('user_id', $request->user()->id)
            ->whereHas('alert', fn (Builder $query) => $query->currentlyActive())
            ->with('alert.source', 'alert.riskAreas')
            ->firstOrFail();

        if ($recipient->read_at === null) {
            $recipient->update(['read_at' => now()]);
        }

        if ($this->isApiRequest($request)) {
            return AlertResource::make($recipient->alert);
        }

        return redirect()->away(
            rtrim((string) config('services.frontend.url'), '/').'/my-alerts/'.$alert->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAlert(Request $request): array
    {
        $sourceRule = Rule::exists('hazard_sources', 'id')->where('verification_status', 'verified');
        if ($request->filled('hazard_type')) {
            $sourceRule->where('source_type', $request->input('hazard_type'));
        }
        $riskAreaRule = Rule::exists('risk_areas', 'id')->where('is_active', true);
        if ($request->input('hazard_type') === HazardType::Fire->value && $request->filled('barangay')) {
            $riskAreaRule->where('barangay', $request->input('barangay'));
        }

        $validated = $request->validate([
            'hazard_source_id' => [
                'required',
                $sourceRule,
            ],
            'hazard_type' => ['sometimes', Rule::in([HazardType::Weather->value, HazardType::Fire->value])],
            'barangay' => ['required_if:hazard_type,fire', 'nullable', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
            'safety_instructions' => ['required', 'string', 'max:5000'],
            'severity' => ['required', Rule::in(['advisory', 'watch', 'warning', 'emergency'])],
            'intensity' => ['sometimes', 'required', 'integer', 'between:1,5'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'risk_area_ids' => ['required', 'array', 'min:1'],
            'risk_area_ids.*' => [
                'required',
                'integer',
                'distinct',
                $riskAreaRule,
            ],
        ]);

        $validated['starts_at'] = CarbonImmutable::parse($validated['starts_at'], 'Asia/Manila')->utc();
        $validated['ends_at'] = filled($validated['ends_at'] ?? null)
            ? CarbonImmutable::parse($validated['ends_at'], 'Asia/Manila')->utc()
            : null;

        return $validated;
    }

    private function isApiRequest(Request $request): bool
    {
        return $request->routeIs('api.v1.*');
    }
}
