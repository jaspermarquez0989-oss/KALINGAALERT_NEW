<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RiskArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'barangay',
        'sitio',
        'classification',
        'boundary',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'boundary' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function alerts(): BelongsToMany
    {
        return $this->belongsToMany(Alert::class)->withTimestamps();
    }
}
