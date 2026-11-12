<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(?User $actor, string $action, Model|string $subject, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => is_string($subject) ? $subject : $subject::class,
            'subject_id' => is_string($subject) ? null : $subject->getKey(),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
