<?php

namespace App\Models;

use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

#[Fillable(['name', 'email', 'password', 'role', 'barangay', 'sitio', 'location_latitude', 'location_longitude', 'is_active'])]
#[Hidden(['password', 'remember_token', 'location_latitude', 'location_longitude'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function getJWTIdentifier(): int|string
    {
        return $this->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function isStaff(): bool
    {
        return in_array($this->role, UserRole::staffValues(), true);
    }

    public function alertRecipients(): HasMany
    {
        return $this->hasMany(AlertRecipient::class);
    }

    public function unreadAlertCount(): int
    {
        return $this->alertRecipients()
            ->whereNull('read_at')
            ->whereHas('alert', fn (Builder $query) => $query->currentlyActive())
            ->count();
    }

    public function createdRiskAreas(): HasMany
    {
        return $this->hasMany(RiskArea::class, 'created_by');
    }

    public function createdHazardSources(): HasMany
    {
        return $this->hasMany(HazardSource::class, 'created_by');
    }

    public function createdAlerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'created_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'location_latitude' => 'float',
            'location_longitude' => 'float',
            'is_active' => 'boolean',
        ];
    }
}
