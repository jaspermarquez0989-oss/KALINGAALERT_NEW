<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HazardSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_type',
        'organization',
        'external_id',
        'reference',
        'title',
        'details',
        'source_url',
        'issued_at',
        'verification_status',
        'created_by',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
}
