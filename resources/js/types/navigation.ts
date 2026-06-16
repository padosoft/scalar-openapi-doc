import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    // When true, render a native full-page anchor instead of an Inertia
    // client-side visit. Required for routes served outside Inertia (e.g. the
    // Scalar `/scalar` page): an Inertia visit to a non-Inertia HTML response
    // is shown in Inertia's sandboxed error-modal iframe, which renders blank
    // because Scalar's spec fetch is blocked from the iframe's opaque origin.
    external?: boolean;
};
