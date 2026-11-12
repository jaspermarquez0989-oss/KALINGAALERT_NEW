<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AlertRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AlertRecipient */
class AlertRecipientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'alert' => AlertResource::make($this->whenLoaded('alert')),
        ];
    }
}
