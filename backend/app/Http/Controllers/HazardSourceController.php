<?php

namespace App\Http\Controllers;

use App\Enums\HazardType;
use App\Enums\UserRole;
use App\Http\Resources\Api\V1\HazardSourceResource;
use App\Models\HazardSource;
use App\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HazardSourceController extends Controller
{
    public function index(Request $request): View|AnonymousResourceCollection
    {
        $query = HazardSource::query()->with(['creator', 'verifier']);

        if ($request->routeIs('api.v1.*')) {
            $filters = $request->validate([
                'source_type' => ['sometimes', Rule::in([HazardType::Weather->value, HazardType::Fire->value])],
                'verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'rejected'])],
                'sort' => ['sometimes', Rule::in(['issued_at', 'created_at', 'title'])],
                'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
                'per_page' => ['sometimes', 'integer', 'between:1,100'],
            ]);

            $query
                ->when(isset($filters['source_type']), fn (Builder $builder) => $builder->where('source_type', $filters['source_type']))
                ->when(
                    isset($filters['verification_status']),
                    fn (Builder $builder) => $builder->where('verification_status', $filters['verification_status']),
                )
                ->orderBy($filters['sort'] ?? 'issued_at', $filters['direction'] ?? 'desc')
                ->orderByDesc('id');

            return HazardSourceResource::collection(
                $query->paginate($filters['per_page'] ?? 15)->withQueryString(),
            );
        }

        return view('hazard-sources.index', [
            'sources' => $query->orderByDesc('issued_at')->paginate(15),
        ]);
    }

    public function show(HazardSource $hazardSource): HazardSourceResource
    {
        return HazardSourceResource::make($hazardSource->load(['creator', 'verifier']));
    }

    public function create(): View
    {
        return view('hazard-sources.form');
    }

    public function showReview(HazardSource $hazardSource): View
    {
        return view('hazard-sources.review', [
            'source' => $hazardSource->load(['creator', 'verifier']),
        ]);
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'source_type' => ['required', Rule::in([HazardType::Weather->value, HazardType::Fire->value])],
            'reference' => ['nullable', 'string', 'max:150'],
            'title' => ['required', 'string', 'max:180'],
            'details' => ['required', 'string', 'max:10000'],
            'source_url' => ['nullable', 'url:https', 'max:2048'],
            'issued_at' => ['required', 'date', 'before_or_equal:now'],
        ]);

        if (
            $request->user()->role === UserRole::BfpOfficer->value
            && $validated['source_type'] !== HazardType::Fire->value
        ) {
            abort(403);
        }

        $source = DB::transaction(function () use ($validated, $request, $audit): HazardSource {
            $source = HazardSource::create([
                ...$validated,
                'organization' => $validated['source_type'] === HazardType::Weather->value ? 'PAGASA' : 'Bureau of Fire Protection (BFP)',
                'verification_status' => 'pending',
                'created_by' => $request->user()->id,
            ]);

            $audit->record($request->user(), 'hazard_source.created', $source, [
                'source_type' => $source->source_type,
                'organization' => $source->organization,
                'reference' => $source->reference,
            ]);

            return $source;
        });

        if ($request->routeIs('api.v1.*')) {
            return response()->json([
                'message' => 'Official source record submitted for review.',
                'data' => HazardSourceResource::make($source->load(['creator', 'verifier'])),
            ], 201);
        }

        return redirect()->route('hazard-sources.index')->with('status', 'Official source record submitted for review.');
    }

    public function review(Request $request, HazardSource $hazardSource, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'rejected'])],
        ]);

        if ($hazardSource->created_by === $request->user()->id) {
            if ($request->routeIs('api.v1.*')) {
                return response()->json([
                    'message' => 'A source record must be reviewed by a different authorized user.',
                ], 403);
            }

            return back()->withErrors(['decision' => 'A source record must be reviewed by a different authorized user.']);
        }

        if ($hazardSource->verification_status !== 'pending') {
            if ($request->routeIs('api.v1.*')) {
                return response()->json(['message' => 'Only pending source records can be reviewed.'], 409);
            }

            return back()->withErrors(['decision' => 'Only pending source records can be reviewed.']);
        }

        DB::transaction(function () use ($hazardSource, $validated, $request, $audit): void {
            $hazardSource->update([
                'verification_status' => $validated['decision'],
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ]);

            $audit->record($request->user(), 'hazard_source.'.$validated['decision'], $hazardSource, [
                'source_type' => $hazardSource->source_type,
                'reference' => $hazardSource->reference,
            ]);
        });

        if ($request->routeIs('api.v1.*')) {
            return response()->json([
                'message' => 'Source record '.$validated['decision'].'.',
                'data' => HazardSourceResource::make($hazardSource->fresh()->load(['creator', 'verifier'])),
            ]);
        }

        return back()->with('status', 'Source record '.$validated['decision'].'.');
    }
}
