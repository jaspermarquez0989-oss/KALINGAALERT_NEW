<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alert extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'hazard_source_id',
        'fire_risk_prediction_id',
        'affected_barangay',
        'affected_latitude',
        'affected_longitude',
        'title',
        'message',
        'safety_instructions',
        'severity',
        'intensity',
        'status',
        'starts_at',
        'ends_at',
        'created_by',
        'approved_by',
        'approved_at',
        'published_at',
        'supersedes_id',
    ];

    protected function casts(): array
    {
        return [
            'intensity' => 'integer',
            'affected_latitude' => 'float',
            'affected_longitude' => 'float',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function scopeCurrentlyActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where(fn (Builder $builder) => $builder->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $builder) => $builder->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(HazardSource::class, 'hazard_source_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function riskAreas(): BelongsToMany
    {
        return $this->belongsToMany(RiskArea::class)->withPivot('coverage_snapshot')->withTimestamps();
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AlertRecipient::class);
    }

    public function isCurrentlyActive(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
