<?php

namespace App\Http\Controllers;

use App\Http\Resources\Api\V1\RiskAreaResource;
use App\Models\RiskArea;
use App\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RiskAreaController extends Controller
{
    public function myCoverage(): AnonymousResourceCollection
    {
        $areas = RiskArea::query()
            ->where('is_active', true)
            ->orderBy('barangay')
            ->orderBy('name')
            ->get();

        return RiskAreaResource::collection($areas);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = RiskArea::query()->with('creator');

        $activeFilter = $request->query('is_active');

        if (is_string($activeFilter) && in_array(strtolower($activeFilter), ['true', 'false'], true)) {
            $request->merge(['is_active' => $activeFilter === 'true']);
        }

        $filters = $request->validate([
            'barangay' => ['sometimes', 'string', 'max:120'],
            'classification' => ['sometimes', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['name', 'barangay', 'classification', 'created_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $sort = $filters['sort'] ?? 'barangay';

        $query
            ->when(isset($filters['barangay']), fn (Builder $builder) => $builder->where('barangay', $filters['barangay']))
            ->when(
                isset($filters['classification']),
                fn (Builder $builder) => $builder->where('classification', $filters['classification']),
            )
            ->when(
                isset($filters['is_active']),
                fn (Builder $builder) => $builder->where('is_active', $request->boolean('is_active')),
            )
            ->orderBy($sort, $filters['direction'] ?? 'asc')
            ->when($sort !== 'name', fn (Builder $builder) => $builder->orderBy('name'))
            ->orderBy('id');

        return RiskAreaResource::collection(
            $query->paginate($filters['per_page'] ?? 15)->withQueryString(),
        );
    }

    public function show(RiskArea $riskArea): RiskAreaResource
    {
        return RiskAreaResource::make($riskArea->load('creator'));
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        $validated = $this->validateArea($request);
        $area = DB::transaction(function () use ($validated, $request, $audit): RiskArea {
            $area = RiskArea::create([
                ...$validated,
                'is_active' => $request->boolean('is_active'),
                'created_by' => $request->user()->id,
            ]);

            $audit->record($request->user(), 'risk_area.created', $area, [
                'name' => $area->name,
                'barangay' => $area->barangay,
                'classification' => $area->classification,
                'latitude' => $area->latitude,
                'longitude' => $area->longitude,
            ]);

            return $area;
        });

        if ($request->routeIs('api.v1.*')) {
            return response()->json([
                'message' => 'Vulnerable area created.',
                'data' => RiskAreaResource::make($area->load('creator')),
            ], 201);
        }

        return redirect()->route('risk-areas.index')->with('status', 'Vulnerable area created.');
    }

    public function update(Request $request, RiskArea $riskArea, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        if ($request->isMethod('PATCH')) {
            $request->merge([
                'name' => $request->input('name', $riskArea->name),
                'barangay' => $request->input('barangay', $riskArea->barangay),
                'sitio' => $request->input('sitio', $riskArea->sitio),
                'classification' => $request->input('classification', $riskArea->classification),
                'boundary' => $request->input('boundary', $riskArea->boundary),
                'latitude' => $request->input('latitude', $riskArea->latitude),
                'longitude' => $request->input('longitude', $riskArea->longitude),
                'is_active' => $request->input('is_active', $riskArea->is_active),
            ]);
        }

        $validated = $this->validateArea($request);
        DB::transaction(function () use ($validated, $request, $riskArea, $audit): void {
            $previousBoundary = $riskArea->boundary;
            $previousLocation = ['latitude' => $riskArea->latitude, 'longitude' => $riskArea->longitude];
            $riskArea->update([
                ...$validated,
                'is_active' => $request->boolean('is_active'),
            ]);

            $audit->record($request->user(), 'risk_area.updated', $riskArea, [
                'name' => $riskArea->name,
                'barangay' => $riskArea->barangay,
                'classification' => $riskArea->classification,
                'previous_boundary' => $previousBoundary,
                'updated_boundary' => $riskArea->boundary,
                'previous_location' => $previousLocation,
                'updated_location' => ['latitude' => $riskArea->latitude, 'longitude' => $riskArea->longitude],
            ]);
        });

        if ($request->routeIs('api.v1.*')) {
            return response()->json([
                'message' => 'Vulnerable area updated.',
                'data' => RiskAreaResource::make($riskArea->fresh()->load('creator')),
            ]);
        }

        return redirect()->route('risk-areas.index')->with('status', 'Vulnerable area updated.');
    }

    public function toggle(Request $request, RiskArea $riskArea, AuditRecorder $audit): RedirectResponse|JsonResponse
    {
        DB::transaction(function () use ($request, $riskArea, $audit): void {
            $riskArea->update(['is_active' => ! $riskArea->is_active]);
            $audit->record($request->user(), $riskArea->is_active ? 'risk_area.activated' : 'risk_area.deactivated', $riskArea);
        });

        if ($request->routeIs('api.v1.*')) {
            return response()->json([
                'message' => 'Vulnerable area status updated.',
                'data' => RiskAreaResource::make($riskArea->fresh()->load('creator')),
            ]);
        }

        return back()->with('status', 'Vulnerable area status updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateArea(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:150'],
            'barangay' => ['required', 'string', 'max:120'],
            'sitio' => ['nullable', 'string', 'max:120'],
            'classification' => ['required', 'string', 'max:80'],
            'boundary' => $request->routeIs('api.v1.*')
                ? ['required', 'array']
                : ['required', 'json'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'is_active' => ['required', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            if ($validator->errors()->has('boundary')) {
                return;
            }

            $boundary = $request->input('boundary');

            if (is_string($boundary)) {
                $boundary = json_decode($boundary, true);
            }

            if (! is_array($boundary)) {
                $validator->errors()->add('boundary', 'The map boundary must be a GeoJSON polygon.');

                return;
            }

            $ring = $boundary['coordinates'][0] ?? null;

            if (
                ($boundary['type'] ?? null) !== 'Polygon'
                || ! is_array($boundary['coordinates'] ?? null)
                || count($boundary['coordinates']) !== 1
                || ! is_array($ring)
                || count($ring) < 4
            ) {
                $validator->errors()->add('boundary', 'Draw a closed map boundary with at least three points.');

                return;
            }

            foreach ($ring as $point) {
                if (
                    ! is_array($point)
                    || count($point) < 2
                    || ! is_numeric($point[0])
                    || ! is_numeric($point[1])
                    || $point[0] < -180
                    || $point[0] > 180
                    || $point[1] < -90
                    || $point[1] > 90
                ) {
                    $validator->errors()->add('boundary', 'The map boundary contains an invalid coordinate.');

                    return;
                }
            }

            if ($ring[0][0] != $ring[array_key_last($ring)][0] || $ring[0][1] != $ring[array_key_last($ring)][1]) {
                $validator->errors()->add('boundary', 'The map boundary must be closed.');

                return;
            }

            if (
                $validator->errors()->has('latitude')
                || $validator->errors()->has('longitude')
                || ! $this->containsPoint(
                    (float) $request->input('longitude'),
                    (float) $request->input('latitude'),
                    $ring,
                )
            ) {
                if (! $validator->errors()->has('latitude') && ! $validator->errors()->has('longitude')) {
                    $validator->errors()->add('latitude', 'The center point must be inside the risk-area boundary.');
                }
            }
        });

        $validated = $validator->validate();
        $validated['boundary'] = is_string($validated['boundary'])
            ? json_decode($validated['boundary'], true, flags: JSON_THROW_ON_ERROR)
            : $validated['boundary'];

        return $validated;
    }

    /**
     * @param  list<array{0: float|int, 1: float|int}>  $ring
     */
    private function containsPoint(float $longitude, float $latitude, array $ring): bool
    {
        $inside = false;
        $count = count($ring);

        for ($current = 0, $previous = $count - 1; $current < $count; $previous = $current++) {
            [$currentLongitude, $currentLatitude] = $ring[$current];
            [$previousLongitude, $previousLatitude] = $ring[$previous];

            $crossProduct = ($latitude - $currentLatitude) * ($previousLongitude - $currentLongitude)
                - ($longitude - $currentLongitude) * ($previousLatitude - $currentLatitude);

            if (
                abs($crossProduct) < 1.0e-10
                && $longitude >= min($currentLongitude, $previousLongitude) - 1.0e-10
                && $longitude <= max($currentLongitude, $previousLongitude) + 1.0e-10
                && $latitude >= min($currentLatitude, $previousLatitude) - 1.0e-10
                && $latitude <= max($currentLatitude, $previousLatitude) + 1.0e-10
            ) {
                return true;
            }

            if (($currentLatitude > $latitude) !== ($previousLatitude > $latitude)) {
                $intersection = ($previousLongitude - $currentLongitude)
                    * ($latitude - $currentLatitude)
                    / (($previousLatitude - $currentLatitude) ?: PHP_FLOAT_EPSILON)
                    + $currentLongitude;

                if ($longitude < $intersection) {
                    $inside = ! $inside;
                }
            }
        }

        return $inside;
    }
}
