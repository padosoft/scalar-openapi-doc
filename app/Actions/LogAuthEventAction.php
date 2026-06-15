<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuthEvent;
use App\Models\AuthLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persist one immutable authentication audit event.
 */
final class LogAuthEventAction
{
    public function handle(?int $userId, string $email, AuthEvent $event, ?string $ipAddress, ?string $userAgent): void
    {
        try {
            AuthLog::query()->create([
                'user_id' => $userId,
                'email' => $email,
                'event' => $event,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to persist auth log row.', [
                'event' => $event->value,
                'user_id' => $userId,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
