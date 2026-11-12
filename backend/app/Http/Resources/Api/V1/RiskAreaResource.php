<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RiskArea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RiskArea */
class RiskAreaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->coverageSnapshot();

        return [
            'id' => $this->id,
            'name' => $snapshot['name'] ?? $this->name,
            'barangay' => $snapshot['barangay'] ?? $this->barangay,
            'sitio' => $snapshot['sitio'] ?? $this->sitio,
            'classification' => $snapshot['classification'] ?? $this->classification,
            'boundary' => $snapshot['boundary'] ?? $this->boundary,
            'is_active' => $this->when($snapshot === [], $this->is_active),
            'creator' => UserResource::make($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function coverageSnapshot(): array
    {
        $snapshot = $this->pivot?->coverage_snapshot;

        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        return is_array($snapshot) ? $snapshot : [];
    }
}
