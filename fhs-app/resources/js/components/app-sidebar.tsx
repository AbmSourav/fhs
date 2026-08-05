import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Boxes, ChartColumn, FileText, LayoutGrid, Package, PhoneCall, Receipt, ShoppingBag, Users } from 'lucide-react';
import AppLogo from './app-logo';

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
    {
        title: 'CRM',
        url: '/crm',
        icon: PhoneCall,
    },
    {
        title: 'Reports',
        url: '/reports',
        icon: FileText,
    },
];

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
];

export function AppSidebar() {
    const { auth, userRoles } = usePage<SharedData>().props;

    // Compared against the enum's own keys, which are the stored values. Going
    // through the labels would break the moment one is reworded.
    const userRole = auth.user?.permission?.role?.toLowerCase();
    const founderOrInvestor = userRole !== undefined && Object.keys(userRoles).includes(userRole);

    const items = auth.isAdmin || founderOrInvestor ? [...mainNavItems, ...adminNavItems] : mainNavItems;

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
