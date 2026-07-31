import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type CustomerFormValues } from '@/types/customer';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
    { title: 'Edit', href: '#' },
];

export default function CustomerEdit({ customer }: { customer: CustomerFormValues }) {
    const { data, setData, patch, errors, processing } = useForm({
        name: customer.name,
        mobile_number: customer.mobile_number,
        address: customer.address,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(`/customers/update/${customer.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit customer" />

            <div className="px-4 py-6">
                <Button variant="outline" asChild className="mb-10 shrink-0 self-start">
                    <Link href="/customers">
                        <ArrowLeft className="mr-1 size-4" />
                        Back to Customers
                    </Link>
                </Button>

                <div className="mt-6 grid gap-10 lg:grid-cols-[minmax(0,28rem)_minmax(0,1fr)]">
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Customer name"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="mobile_number">
                                Mobile number <span className="text-muted-foreground font-normal">(optional)</span>
                            </Label>
                            <Input
                                id="mobile_number"
                                value={data.mobile_number}
                                onChange={(e) => setData('mobile_number', e.target.value)}
                                placeholder="01700000000"
                            />
                            <p className="text-muted-foreground text-xs">
                                This identifies the customer when recording a sale, so it cannot be shared with anyone else.
                            </p>
                            <InputError message={errors.mobile_number} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="address">
                                Address <span className="text-muted-foreground font-normal">(optional)</span>
                            </Label>
                            <Input
                                id="address"
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                                placeholder="Delivery address"
                            />
                            <InputError message={errors.address} />
                        </div>

                        <div className="flex items-center gap-3">
                            <Button disabled={processing}>Save changes</Button>

                            <Button variant="secondary" className="px-6" asChild>
                                <Link href="/customers">Cancel</Link>
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
