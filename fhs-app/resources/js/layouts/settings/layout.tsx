import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        url: '/settings/profile',
        icon: null,
    },
    {
        title: 'Password',
        url: '/settings/password',
        icon: null,
    },
    {
        title: 'Appearance',
        url: '/settings/appearance',
        icon: null,
    },
];

/** Managing accounts is an administrator's job, so the tab is theirs alone. */
const adminNavItems: NavItem[] = [
    {
        title: 'Users',
        url: '/settings/users',
        icon: null,
    },
];

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const currentPath = window.location.pathname;
    const { auth } = usePage<SharedData>().props;

    // Keyed on canManageUsers rather than isAdmin: a founder holds the admin
    // gate but not this one. Hiding the tab is presentation only — the route is
    // gated server-side by the `manage-users` gate.
    const items = auth.canManageUsers ? [...sidebarNavItems, ...adminNavItems] : sidebarNavItems;

    return (
        <div className="px-4 py-6">
            <Heading title="" />

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:mt-[-30px] lg:w-48">
                    <nav className="grid grid-cols-3 gap-2 lg:grid-cols-1">
                        {items.map((item) => (
                            <Button
                                key={item.url}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start rounded border', {
                                    'bg-muted': currentPath === item.url,
                                })}
                            >
                                <Link href={item.url} prefetch>
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
