<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FireRiskPrediction extends Model
{
    protected $fillable = [
        'barangay',
        'latitude',
        'longitude',
        'score_percent',
        'threshold',
        'threshold_met',
        'model_version',
        'prediction_radius_meters',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'score_percent' => 'float',
            'threshold' => 'float',
            'threshold_met' => 'boolean',
            'prediction_radius_meters' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
