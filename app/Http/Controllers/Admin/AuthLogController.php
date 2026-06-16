<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AuthEvent;
use App\Http\Controllers\Controller;
use App\Models\AuthLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

final class AuthLogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = AuthLog::query()->orderByDesc('created_at');

        $event = $request->query('event');
        if (is_string($event) && $event !== '') {
            $query->where('event', $event);
        }

        $email = $request->query('email');
        if (is_string($email) && $email !== '') {
            $query->where('email', 'like', "%{$email}%");
        }

        $start = $request->query('start_date');
        if (is_string($start) && $start !== '') {
            $startDate = Carbon::parse($start);
            $query->where('created_at', '>=', $startDate);
        }

        $end = $request->query('end_date');
        if (is_string($end) && $end !== '') {
            // A date-only value (e.g. "2026-06-16") parses to midnight; extend it
            // to the end of that day so the range is inclusive of the whole day.
            $endDate = Carbon::parse($end)->endOfDay();
            $query->where('created_at', '<=', $endDate);
        }

        $rows = $query
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuthLog $row): array => [
                'id' => $row->id,
                'email' => $row->email,
                'event' => $row->event->value,
                'user_id' => $row->user_id,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'created_at' => $row->created_at->toDateTimeString(),
            ])
            ->toArray();

        $events = array_map(static fn (AuthEvent $item): string => $item->value, AuthEvent::cases());

        return Inertia::render('admin/auth-logs/index', [
            'rows' => $rows,
            'events' => $events,
            'filters' => [
                'event' => is_string($event) ? $event : '',
                'email' => is_string($email) ? $email : '',
                'startDate' => is_string($start) ? $start : '',
                'endDate' => is_string($end) ? $end : '',
            ],
        ]);
    }
}
