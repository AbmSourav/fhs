import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Boxes, ChartColumn, LayoutGrid, Package, Receipt, ShoppingBag, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Statistics',
        url: '/statistics',
        icon: ChartColumn,
    },
    {
        title: 'Catalogue',
        url: '/catalogue',
        icon: Package,
    },
    {
        title: 'Inventories',
        url: '/inventories',
        icon: Boxes,
    },
    {
        title: 'Orders',
        url: '/orders',
        icon: ShoppingBag,
    },
    {
        title: 'Customers',
        url: '/customers',
        icon: Users,
    },
    {
        title: 'Other Expenses',
        url: '/expenses',
        icon: Receipt,
    },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;

    // Hiding the link is presentation only — the routes are gated server-side
    // by the `admin` gate.
    const items = auth.isAdmin ? [...mainNavItems, ...adminNavItems] : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
