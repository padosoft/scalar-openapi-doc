import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { DataTable } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AuthLogRow = {
    id: number;
    email: string;
    event: string;
    user_id: number | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string | null;
};

type AuthLogPagination = {
    data: AuthLogRow[];
    current_page: number;
    last_page: number;
};

type AuthLogProps = {
    rows: AuthLogPagination;
    events: string[];
    filters: {
        event: string;
        email: string;
        startDate: string;
        endDate: string;
    };
};

export default function AdminAuthLogs() {
    const { rows, events, filters } = usePage<AuthLogProps>().props;
    const paginator = rows;
    const [search, setSearch] = useState({
        email: filters.email,
        event: filters.event,
        startDate: filters.startDate,
        endDate: filters.endDate,
    });

    const eventOptions = useMemo(
        () => events.map((event) => ({ value: event, label: event })),
        [events],
    );

    const applyFilters = (values = search): void => {
        router.visit('/auth-logs', {
            preserveState: true,
            preserveScroll: true,
            only: ['rows', 'filters'],
            method: 'get',
            // The controller reads snake_case query params; also drop any stale
            // `page` so a narrower filter never lands on an out-of-range page
            // that would render as an empty (apparently blank) result set.
            data: {
                email: values.email,
                event: values.event,
                start_date: values.startDate,
                end_date: values.endDate,
                page: undefined,
            },
        });
    };

    const resetFilters = (): void => {
        const reset = { email: '', event: '', startDate: '', endDate: '' };
        setSearch(reset);
        // Submit the reset values directly: setSearch is async, so reading
        // `search` here would still hold the old (filtered) values.
        applyFilters(reset);
    };

    return (
        <>
            <Head title="Authentication logs" />
            <section className="space-y-4">
                <h1 className="text-2xl font-semibold">Authentication logs</h1>

                <form className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="space-y-2">
                        <Label htmlFor="email">Email</Label>
                        <Input
                            id="email"
                            value={search.email}
                            onChange={(event) =>
                                setSearch((current) => ({
                                    ...current,
                                    email: event.currentTarget.value,
                                }))
                            }
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="event">Event</Label>
                        <select
                            id="event"
                            value={search.event}
                            onChange={(eventEl) => {
                                setSearch((current) => ({
                                    ...current,
                                    event: eventEl.currentTarget.value,
                                }));
                            }}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none"
                        >
                            <option value="">All</option>
                            {eventOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="start_date">From</Label>
                        <Input
                            id="start_date"
                            type="date"
                            value={search.startDate}
                            onChange={(event) =>
                                setSearch((current) => ({
                                    ...current,
                                    startDate: event.currentTarget.value,
                                }))
                            }
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="end_date">To</Label>
                        <Input
                            id="end_date"
                            type="date"
                            value={search.endDate}
                            onChange={(event) =>
                                setSearch((current) => ({
                                    ...current,
                                    endDate: event.currentTarget.value,
                                }))
                            }
                        />
                    </div>

                    <Button
                        type="button"
                        className="self-end"
                        onClick={() => applyFilters()}
                    >
                        Apply
                    </Button>

                    <Button
                        type="button"
                        variant="outline"
                        className="self-end"
                        onClick={resetFilters}
                    >
                        Reset
                    </Button>
                </form>

                <DataTable
                    rows={paginator.data}
                    rowKey="id"
                    emptyMessage="No authentication events match these filters."
                    columns={[
                        {
                            key: 'created_at',
                            label: 'At',
                        },
                        {
                            key: 'email',
                            label: 'Email',
                        },
                        {
                            key: 'event',
                            label: 'Event',
                        },
                        {
                            key: 'user_id',
                            label: 'User',
                            render: (row: AuthLogRow) =>
                                row.user_id === null ? '—' : row.user_id,
                        },
                        {
                            key: 'ip_address',
                            label: 'IP',
                            render: (row: AuthLogRow) => row.ip_address ?? '—',
                        },
                    ]}
                />
            </section>
        </>
    );
}

AdminAuthLogs.layout = {
    breadcrumbs: [
        {
            title: 'Auth Logs',
            href: '/auth-logs',
        },
    ],
};
