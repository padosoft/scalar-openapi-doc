<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\LogAuthEventAction;
use App\Enums\AuthEvent;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Queue\ShouldQueue;

final class LogSuccessfulLogout implements ShouldQueue
{
    public function __construct(private readonly LogAuthEventAction $logAuthEvent) {}

    public function handle(Logout $event): void
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
        if (! $user instanceof User) {
            return;
        }

        $this->logAuthEvent->handle(
            userId: (int) $user->id,
            email: $user->email,
            event: AuthEvent::Logout,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }
}
