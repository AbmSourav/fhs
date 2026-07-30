import AddBrandSheet from '@/components/catalogue/add-brand-sheet';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type BrandOption } from '@/types/brand';
import { type RecentCatalogueItem } from '@/types/catalogue';
import { type InventoryTypeOption } from '@/types/inventory-type';
import { Transition } from '@headlessui/react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Catalogue', href: '/catalogue' },
    { title: 'Setup', href: '/catalogue/setup' },
];

interface SetupProps {
    types: InventoryTypeOption[];
    brands: BrandOption[];
    recentItems: RecentCatalogueItem[];
}

export default function CatalogueSetup({ types, brands, recentItems }: SetupProps) {
    const { data, setData, post, errors, processing, recentlySuccessful, reset } = useForm({
        name: '',
        type: '',
        brand_id: '',
        weight: '12.5',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post('/catalogue', {
            preserveScroll: true,
            // Clear the name too — carrying it to the next item would silently
            // label a different product with the previous one's name.
            onSuccess: () => reset('brand_id', 'name'),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Catalogue setup" />

            <div className="px-4 py-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Button variant="outline" asChild className="mb-10 shrink-0 self-start">
                        <Link href="/catalogue">
                            <ArrowLeft className="mr-1 size-4" />
                            Back to Catalogue
                        </Link>
                    </Button>
                </div>

                <div className="mt-6 grid gap-10 lg:grid-cols-[minmax(0,28rem)_minmax(0,1fr)]">
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">
                                Product name <span className="text-muted-foreground font-normal">(optional)</span>
                            </Label>

                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Name" />

                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="type">Product type</Label>

                            <Select value={data.type} onValueChange={(value) => setData('type', value)}>
                                <SelectTrigger id="type">
                                    <SelectValue placeholder="Select a type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {types.map((type) => (
                                        <SelectItem key={type.value} value={type.value}>
                                            {type.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <InputError message={errors.type} />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-center justify-between gap-2">
                                <Label htmlFor="brand_id">
                                    Brand
                                    {/* <span className="text-muted-foreground font-normal">(optional)</span> */}
                                </Label>

                                <AddBrandSheet />
                            </div>

                            {brands.length > 0 ? (
                                <Select value={data.brand_id} onValueChange={(value) => setData('brand_id', value)}>
                                    <SelectTrigger id="brand_id">
                                        <SelectValue placeholder="No brand" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {brands.map((brand) => (
                                            <SelectItem key={brand.id} value={String(brand.id)}>
                                                {brand.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : (
                                <p className="text-muted-foreground rounded-md border border-dashed px-3 py-2 text-sm">
                                    No brands yet. Items can be added without one.
                                </p>
                            )}

                            <InputError message={errors.brand_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="weight">Weight (kg)</Label>

                            <Input
                                id="weight"
                                type="text"
                                step="0.01"
                                min="0.01"
                                max="9999.99"
                                value={data.weight ?? '12.5'}
                                onChange={(e) => setData('weight', e.target.value)}
                                placeholder="12.5"
                                required
                            />

                            <p className="text-muted-foreground text-xs">
                                Each weight is a separate record with its own stock — a 12.5kg and a 35kg cylinder are different items.
                            </p>

                            <InputError message={errors.weight} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing || !data.type}>Add to catalogue</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-muted-foreground text-sm">Added</p>
                            </Transition>
                        </div>
                    </form>

                    <div className="space-y-4">
                        <HeadingSmall title="Recently added" description="The last items added to the catalogue" />

                        {recentItems.length === 0 ? (
                            <p className="text-muted-foreground rounded-lg border border-dashed px-4 py-8 text-center text-sm">Nothing added yet.</p>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {recentItems.map((item) => (
                                    <li key={item.id} className="px-4 py-3 text-sm font-medium">
                                        {item.display_name}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
