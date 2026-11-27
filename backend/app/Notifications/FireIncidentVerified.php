<?php

namespace App\Notifications;

use App\Models\FireIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FireIncidentVerified extends Notification
{
    use Queueable;

    public function __construct(private readonly FireIncident $incident) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'fire_incident_id' => $this->incident->id,
            'barangay' => $this->incident->barangay,
            'occurred_at' => $this->incident->occurred_at?->toIso8601String(),
            'incident_type' => $this->incident->incident_type,
        ];
    }
}
