import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatDate } from '@/lib/datetime';
import { type BreadcrumbItem, type ManagedUser, type RoleOption } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { ShieldCheck, Trash2, UserPlus } from 'lucide-react';
import { type SubmitEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Settings/Users',
        href: '/settings/users',
    },
];

interface Props {
    users: ManagedUser[];
    roles: RoleOption[];
    /** So the page can refuse to offer deleting the account in use. */
    currentUserId: number;
}

export default function Users({ users, roles, currentUserId }: Props) {
    const { data, setData, post, errors, processing, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: '',
    });

    const submit: SubmitEventHandler<HTMLFormElement> = (e) => {
        e.preventDefault();

        post(route('users.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Users" description="Add an account, then pass the credentials on in person" />

                    {/* The whole list folds away as one. Adding a user is the
                        common errand here; reading the roll call is not, so it
                        stays out of the way until asked for. */}
                    <Accordion type="single" collapsible defaultValue="users" className="w-full">
                        <AccordionItem value="users" className="border-none">
                            <AccordionTrigger className="py-2 hover:no-underline">
                                <span className="text-sm font-medium">
                                    {users.length} {users.length === 1 ? 'account' : 'accounts'}
                                </span>
                            </AccordionTrigger>

                            <AccordionContent>
                                <ul className="space-y-2 pt-1">
                                    {users.map((user) => (
                                        <UserRow key={user.id} user={user} isSelf={user.id === currentUserId} />
                                    ))}
                                </ul>
                            </AccordionContent>
                        </AccordionItem>
                    </Accordion>
                </div>

                <form onSubmit={submit} className="mt-10 space-y-6">
                    <HeadingSmall title="Add a user" description="They will be able to sign in straight away" />

                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            autoComplete="off"
                            placeholder="Sourav"
                            required
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            autoComplete="off"
                            placeholder="sourav@example.com"
                            required
                        />
                        <p className="text-muted-foreground text-xs">What they sign in with.</p>
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="role">Role</Label>
                        <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                            <SelectTrigger id="role">
                                <SelectValue placeholder="Select role" />
                            </SelectTrigger>
                            <SelectContent>
                                {roles.map((role) => (
                                    <SelectItem key={role.value} value={role.value}>
                                        {role.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {/* Administrators are set by deployment, so the list
                            deliberately offers no way to create one. */}
                        <p className="text-muted-foreground text-xs">Administrators are set by deployment, not here.</p>
                        <InputError message={errors.role} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            autoComplete="new-password"
                            required
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">Confirm password</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            autoComplete="new-password"
                            required
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <Button disabled={processing} className="gap-2">
                        <UserPlus className="size-4" />
                        Add user
                    </Button>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}

/** One account, with the option to remove it. */
function UserRow({ user, isSelf }: { user: ManagedUser; isSelf: boolean }) {
    const [confirming, setConfirming] = useState(false);

    // Deleting your own account locks you out with no way back, and an
    // administrator's access comes from config rather than this row.
    const blocked = isSelf || user.is_admin;

    const remove = () => {
        router.delete(route('users.destroy', user.id), {
            preserveScroll: true,
            onFinish: () => setConfirming(false),
        });
    };

    return (
        <li className="rounded-lg border p-3">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate text-sm font-medium">
                        {user.name}
                        {isSelf && <span className="text-muted-foreground ml-2 text-xs font-normal">(you)</span>}
                    </p>
                    <p className="text-muted-foreground truncate text-xs">{user.email}</p>

                    <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                        {user.is_admin ? (
                            <span className="flex items-center gap-1 text-xs font-medium">
                                <ShieldCheck className="size-3" />
                                Administrator
                            </span>
                        ) : (
                            <span className="text-xs font-medium">{user.role_label ?? 'No role'}</span>
                        )}

                        <span className="text-muted-foreground text-xs">Added {formatDate.format(new Date(user.created_at))}</span>
                    </div>
                </div>

                {!blocked &&
                    (confirming ? (
                        <div className="flex shrink-0 items-center gap-2">
                            <Button variant="destructive" size="sm" className="h-7 px-2 text-xs" onClick={remove}>
                                Delete
                            </Button>
                            <Button variant="ghost" size="sm" className="h-7 px-2 text-xs" onClick={() => setConfirming(false)}>
                                Cancel
                            </Button>
                        </div>
                    ) : (
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-7 shrink-0 gap-1 px-2 text-xs"
                            onClick={() => setConfirming(true)}
                        >
                            <Trash2 className="size-3" />
                            Remove
                        </Button>
                    ))}
            </div>
        </li>
    );
}
