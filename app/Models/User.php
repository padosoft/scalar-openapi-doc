<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * OpenAPI tags granted to this user.
     *
     * @return HasMany<UserAllowedTag, $this>
     */
    public function allowedTags(): HasMany
    {
        return $this->hasMany(UserAllowedTag::class);
    }

    /**
     * OpenAPI operations granted to this user.
     *
     * @return HasMany<UserAllowedEndpoint, $this>
     */
    public function allowedEndpoints(): HasMany
    {
        return $this->hasMany(UserAllowedEndpoint::class);
    }

    /**
     * Authentication audit rows for this user.
     *
     * @return HasMany<AuthLog, $this>
     */
    public function authLogs(): HasMany
    {
        return $this->hasMany(AuthLog::class);
    }

    /**
     * Scalar playground servers this user may see in the spec's `servers` list.
     * Admins bypass this (they see all active servers); a user with no granted
     * servers sees none.
     *
     * @return BelongsToMany<ScalarServer, $this>
     */
    public function allowedServers(): BelongsToMany
    {
        return $this->belongsToMany(ScalarServer::class, 'user_allowed_servers')
            ->withTimestamps();
    }
}
