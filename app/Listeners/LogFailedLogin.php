<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\LogAuthEventAction;
use App\Enums\AuthEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Queue\ShouldQueue;

final class LogFailedLogin implements ShouldQueue
{
    public function __construct(private readonly LogAuthEventAction $logAuthEvent) {}

    public function handle(Failed $event): void
    {
        $request = request();
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent();
        if (! is_string($ipAddress)) {
            $ipAddress = null;
        }
        if (! is_string($userAgent)) {
            $userAgent = null;
        }

        $user = $event->user;
        $userId = null;
        if ($user instanceof User) {
            $userId = (int) $user->id;
        }

        $rawEmail = $event->credentials['email'] ?? null;
        $email = is_string($rawEmail) ? trim($rawEmail) : '';
        if ($email === '' && $user instanceof User) {
            $email = $user->email;
        }

        $this->logAuthEvent->handle(
            userId: $userId,
            email: $email,
            event: AuthEvent::Failed,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }
}
