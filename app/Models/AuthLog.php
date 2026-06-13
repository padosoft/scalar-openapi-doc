<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuthEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable audit row for an authentication event (login/logout/failed).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $email snapshot, survives user deletion
 * @property AuthEvent $event
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
class AuthLog extends Model
{
    /** Audit rows have only created_at — no updated_at. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['user_id', 'email', 'event', 'ip_address', 'user_agent'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuthEvent::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * The user may be null after deletion (ON DELETE SET NULL).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
