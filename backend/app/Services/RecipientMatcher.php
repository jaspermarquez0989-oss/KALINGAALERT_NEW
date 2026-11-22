<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\RiskArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RecipientMatcher
{
    /**
     * @param  Collection<int, RiskArea>  $riskAreas
     * @return Collection<int, User>
     */
    public function matchResidents(Collection $riskAreas): Collection
    {
        if ($riskAreas->isEmpty()) {
            return new Collection;
        }

        $residents = User::query()
            ->select(['id', 'barangay', 'sitio', 'location_latitude', 'location_longitude'])
            ->where('role', UserRole::CommunityResident->value)
            ->where('is_active', true)
            ->where(function ($query) use ($riskAreas): void {
                $query->whereIn(
                    DB::raw('LOWER(barangay)'),
                    $riskAreas->pluck('barangay')->map(fn (string $barangay): string => mb_strtolower($barangay))->unique()->all(),
                )
                    ->orWhereNotNull('location_latitude');
            })
            ->get();

        return $residents
            ->filter(fn (User $resident): bool => $riskAreas->contains(
                fn (RiskArea $riskArea): bool => $this->matchesArea($resident, $riskArea),
            ))
            ->values();
    }

    public function matchesArea(User $resident, RiskArea $riskArea): bool
    {
        $coverage = $this->coverageSnapshot($riskArea);
        $barangay = $coverage['barangay'] ?? $riskArea->barangay;
        $sitio = $coverage['sitio'] ?? $riskArea->sitio;

        if ($this->normalize($resident->barangay) === $this->normalize($barangay)) {
            $areaSite = $this->normalize($sitio);

            if ($areaSite === '' || $areaSite === $this->normalize($resident->sitio)) {
                return true;
            }
        }

        if ($resident->location_latitude === null || $resident->location_longitude === null) {
            return false;
        }

        $boundary = $coverage['boundary'] ?? $riskArea->boundary;
        $polygon = is_array($boundary) ? ($boundary['coordinates'][0] ?? null) : null;

        return is_array($polygon) && $this->containsPoint(
            (float) $resident->location_longitude,
            (float) $resident->location_latitude,
            $polygon,
        );
    }

    public function deliverActiveAlertsToResident(User $resident): void
    {
        $alerts = Alert::query()
            ->currentlyActive()
            ->get();

        foreach ($alerts as $alert) {
            $resident->alertRecipients()->firstOrCreate(
                ['alert_id' => $alert->id],
                ['delivered_at' => now()],
            );
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function allActiveResidents(): Collection
    {
        return User::query()
            ->select('id')
            ->where('role', UserRole::CommunityResident->value)
            ->where('is_active', true)
            ->get();
    }

    /**
     * @param  list<array{0: float|int, 1: float|int}>  $polygon  GeoJSON longitude/latitude pairs
     */
    private function containsPoint(float $longitude, float $latitude, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);

        for ($current = 0, $previous = $count - 1; $current < $count; $previous = $current++) {
            $currentPoint = $polygon[$current] ?? null;
            $previousPoint = $polygon[$previous] ?? null;

            if (! is_array($currentPoint) || ! is_array($previousPoint)) {
                return false;
            }

            [$currentLongitude, $currentLatitude] = $currentPoint;
            [$previousLongitude, $previousLatitude] = $previousPoint;

            if ($this->pointIsOnSegment(
                $longitude,
                $latitude,
                (float) $currentLongitude,
                (float) $currentLatitude,
                (float) $previousLongitude,
                (float) $previousLatitude,
            )) {
                return true;
            }

            $crossesLatitude = ($currentLatitude > $latitude) !== ($previousLatitude > $latitude);
            $intersection = (($previousLongitude - $currentLongitude) * ($latitude - $currentLatitude)
                / (($previousLatitude - $currentLatitude) ?: PHP_FLOAT_EPSILON))
                + $currentLongitude;

            if ($crossesLatitude && $longitude < $intersection) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function pointIsOnSegment(
        float $longitude,
        float $latitude,
        float $startLongitude,
        float $startLatitude,
        float $endLongitude,
        float $endLatitude,
    ): bool {
        $crossProduct = ($latitude - $startLatitude) * ($endLongitude - $startLongitude)
            - ($longitude - $startLongitude) * ($endLatitude - $startLatitude);

        return abs($crossProduct) < 1.0e-10
            && $longitude >= min($startLongitude, $endLongitude) - 1.0e-10
            && $longitude <= max($startLongitude, $endLongitude) + 1.0e-10
            && $latitude >= min($startLatitude, $endLatitude) - 1.0e-10
            && $latitude <= max($startLatitude, $endLatitude) + 1.0e-10;
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function coverageSnapshot(RiskArea $riskArea): array
    {
        $snapshot = $riskArea->pivot?->coverage_snapshot;

        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        return is_array($snapshot) ? $snapshot : [];
    }
}
