import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Database, FolderGit2, LayoutGrid, Logs, MonitorDot, Shield } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
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

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
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
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
