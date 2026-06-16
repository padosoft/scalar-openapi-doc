import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

// Mock Inertia: `usePage` feeds `useCurrentUrl`, and `Link` is tagged so the
// test can tell an Inertia client-side link apart from a native anchor.
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/dashboard', props: {} }),
    Link: ({
        href,
        children,
    }: {
        href: string | { url: string };
        prefetch?: boolean;
        children: ReactNode;
    }) => (
        <a
            data-link-type="inertia"
            href={typeof href === 'string' ? href : href.url}
        >
            {children}
        </a>
    ),
}));

// Stub the sidebar primitives: the real ones evaluate `window.matchMedia` at
// import time (unavailable in jsdom) and require a SidebarProvider context.
vi.mock('@/components/ui/sidebar', () => ({
    SidebarGroup: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    SidebarGroupLabel: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    SidebarMenu: ({ children }: { children: ReactNode }) => <ul>{children}</ul>,
    SidebarMenuItem: ({ children }: { children: ReactNode }) => (
        <li>{children}</li>
    ),
    SidebarMenuButton: ({
        children,
        isActive,
    }: {
        children: ReactNode;
        isActive?: boolean;
    }) => <div data-active={isActive ? 'true' : 'false'}>{children}</div>,
}));

import { NavMain } from '@/components/nav-main';

describe('NavMain', () => {
    it('renders external items as a native full-page anchor, not an Inertia link', () => {
        render(
            <NavMain
                items={[
                    { title: 'Dashboard', href: '/dashboard' },
                    { title: 'API Reference', href: '/scalar', external: true },
                ]}
            />,
        );

        // Internal item keeps the Inertia client-side link.
        const internal = screen.getByRole('link', { name: 'Dashboard' });
        expect(internal).toHaveAttribute('data-link-type', 'inertia');

        // External item is a plain anchor → full page navigation to /scalar.
        const external = screen.getByRole('link', { name: 'API Reference' });
        expect(external).toHaveAttribute('href', '/scalar');
        expect(external).not.toHaveAttribute('data-link-type');
    });

    it('passes isActive=true only to the item whose href matches the current URL', () => {
        // usePage mock returns url: '/dashboard', so only Dashboard is active.
        render(
            <NavMain
                items={[
                    { title: 'Dashboard', href: '/dashboard' },
                    { title: 'API Reference', href: '/scalar', external: true },
                ]}
            />,
        );

        const dashboardLink = screen.getByRole('link', { name: 'Dashboard' });
        const apiLink = screen.getByRole('link', { name: 'API Reference' });

        // The SidebarMenuButton wrapping each link carries the active state.
        expect(dashboardLink.closest('[data-active]')).toHaveAttribute(
            'data-active',
            'true',
        );
        expect(apiLink.closest('[data-active]')).toHaveAttribute(
            'data-active',
            'false',
        );
    });
});
