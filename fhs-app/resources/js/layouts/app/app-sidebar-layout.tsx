import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { type BreadcrumbItem } from '@/types';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    return (
        // The sidebar and header are application chrome, not content: printing
        // a report should put the document on the page and nothing else.
        <AppShell variant="sidebar">
            <div data-print="hide" className="contents">
                <AppSidebar />
            </div>
            <AppContent variant="sidebar" data-print="surface">
                <div data-print="hide">
                    <AppSidebarHeader breadcrumbs={breadcrumbs} />
                </div>
                {children}
            </AppContent>
        </AppShell>
    );
}
