import AppLogoIcon from '@/components/app-logo-icon';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    /** Accepted for callers that still pass one, but no longer rendered. */
    description?: string;
}

export default function AuthSimpleLayout({ children, title }: AuthLayoutProps) {
    return (
        <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <div className="flex flex-col items-center gap-2 font-medium">
                            <div className="mb-1 flex h-25 w-25 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-25 fill-current text-[var(--foreground)] dark:text-white" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </div>

                        <div className="space-y-2 text-center mb-5">
                            <h1 className="text-xl font-medium">{title}</h1>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
