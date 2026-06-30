import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

// Mutable page props the mocked usePage() returns; set per test before render.
const state = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: ReactNode }) => <>{children}</>,
    router: { visit: vi.fn() },
    useForm: () => ({
        data: {
            name: '',
            email: '',
            password: '',
            role: 'admin',
            grants: { tags: [], endpoints: [], servers: [] },
        },
        setData: vi.fn(),
        put: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
    }),
    usePage: () => ({ props: state.props }),
}));

import UserForm from '@/pages/admin/users/form';

const baseProps = {
    user: null,
    roles: ['admin', 'user'],
    openapi: { tags: [], endpoints: [] },
    servers: [],
};

describe('UserForm openapi status banner', () => {
    it('shows a warning with the redacted detail when the catalog is unavailable', () => {
        state.props = {
            ...baseProps,
            openapiStatus: {
                ok: false,
                failure: {
                    category: 'external_api',
                    label: 'Upstream API error',
                    httpStatus: 503,
                    exceptionClass:
                        'Illuminate\\Http\\Client\\RequestException',
                    message: 'HTTP request returned status code 503',
                },
            },
        };

        render(<UserForm />);

        const banner = screen.getByTestId('openapi-status-warning');
        expect(banner).toBeInTheDocument();
        expect(banner).toHaveTextContent('Upstream API error');
        expect(banner).toHaveTextContent('HTTP 503');
        expect(banner).toHaveTextContent('RequestException');
        // The form itself stays usable.
        expect(
            screen.getByRole('button', { name: 'Create user' }),
        ).toBeInTheDocument();
    });

    it('does not show the warning when the catalog loaded', () => {
        state.props = { ...baseProps, openapiStatus: { ok: true } };

        render(<UserForm />);

        expect(
            screen.queryByTestId('openapi-status-warning'),
        ).not.toBeInTheDocument();
    });
});
