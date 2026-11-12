<?php

namespace App\Http\Resources\Api\V1;

use App\Models\HazardSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HazardSource */
class HazardSourceResource extends JsonResource
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
            'source_type' => $this->source_type,
            'organization' => $this->organization,
            'reference' => $this->reference,
            'title' => $this->title,
            'details' => $this->details,
            'source_url' => $this->source_url,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'verification_status' => $this->verification_status,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'creator' => UserResource::make($this->whenLoaded('creator')),
            'verifier' => UserResource::make($this->whenLoaded('verifier')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
