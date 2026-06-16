import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Database,
    LayoutGrid,
    Logs,
    MonitorDot,
    Shield,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const navItems: NavItem[] = [...mainNavItems];

    if (auth.canViewScalar) {
        navItems.push({
            title: 'API Reference',
            href: '/scalar',
            icon: BookOpen,
            // `/scalar` is rendered by the scalar/laravel package, not Inertia.
            // Use a full-page navigation so Scalar loads on the real origin and
            // can fetch the spec (an Inertia visit shows a blank error modal).
            external: true,
        });
    }

    if (auth.isAdmin) {
        navItems.push({
            title: 'Users',
            href: '/admin/users',
            icon: Shield,
        });
        navItems.push({
            title: 'Servers',
            href: '/servers',
            icon: MonitorDot,
        });
        navItems.push({
            title: 'Auth Logs',
            href: '/auth-logs',
            icon: Logs,
        });
        navItems.push({
            title: 'OpenAPI Cache',
            href: '/openapi-cache',
            icon: Database,
        });
    }

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
