<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FireIncident extends Model
{
    protected $fillable = [
        'occurred_at',
        'barangay',
        'sitio',
        'incident_type',
        'cause',
        'latitude',
        'longitude',
        'injuries',
        'fatalities',
        'property_damage_estimate',
        'source_reference',
        'source_url',
        'details',
        'verification_status',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'injuries' => 'integer',
            'fatalities' => 'integer',
            'property_damage_estimate' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
