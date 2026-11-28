<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Alert */
class AlertResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'safety_instructions' => $this->safety_instructions,
            'severity' => $this->severity,
            'intensity' => $this->intensity,
            'fire_risk_prediction_id' => $this->fire_risk_prediction_id,
            'affected_barangay' => $this->affected_barangay,
            'affected_latitude' => $this->affected_latitude,
            'affected_longitude' => $this->affected_longitude,
            'affected_score_percent' => $this->affected_score_percent,
            'status' => $this->status,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'recipient_count' => $this->when(isset($this->recipients_count), $this->recipients_count),
            'hazard_source' => HazardSourceResource::make($this->whenLoaded('source')),
            'risk_areas' => RiskAreaResource::collection($this->whenLoaded('riskAreas')),
            'creator' => UserResource::make($this->whenLoaded('creator')),
            'approver' => UserResource::make($this->whenLoaded('approver')),
            'supersedes' => self::make($this->whenLoaded('supersedes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
