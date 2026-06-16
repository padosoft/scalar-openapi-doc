import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

const visit = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: ReactNode }) => <>{children}</>,
    router: { visit: (...args: unknown[]) => visit(...args) },
    usePage: () => ({
        props: {
            rows: { data: [], current_page: 1, last_page: 1 },
            events: ['login', 'logout', 'failed'],
            filters: { event: '', email: '', startDate: '', endDate: '' },
        },
    }),
}));

import AdminAuthLogs from '@/pages/admin/auth-logs/index';

describe('AdminAuthLogs', () => {
    it('renders without crashing when there are no rows for the selected event', () => {
        render(<AdminAuthLogs />);

        expect(
            screen.getByRole('heading', { name: 'Authentication logs' }),
        ).toBeInTheDocument();
        // Empty result set must show the table fallback, never a blank screen.
        expect(
            screen.getByText('No authentication events match these filters.'),
        ).toBeInTheDocument();

        // Selecting an event and applying filters should issue a router visit.
        fireEvent.change(screen.getByLabelText('Event'), {
            target: { value: 'login' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
        expect(visit).toHaveBeenCalled();
    });
});
