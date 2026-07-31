import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type PurchasableItem } from '@/types/inventory';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventories' },
    { title: 'Add', href: '/inventories/add' },
];

/** Today in the `yyyy-mm-dd` shape a date input expects, in local time. */
function today(): string {
    const now = new Date();

    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

export default function InventoryAdd({ items }: { items: PurchasableItem[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({
        catalogue_id: '',
        supplier: '',
        invoice_ref: '',
        purchased_at: today(),
        new_stock: true,
        filled_quantity: '',
        empty_quantity: '',
        shell_unit_cost: '',
        gas_unit_cost: '',
        quantity: '',
        unit_cost: '',
        transport_cost: '',
        other_cost: '',
    });

    // Which half of the form applies is a fact about the product, so it is read
    // from the chosen item rather than asked separately.
    const selected = items.find((item) => String(item.id) === data.catalogue_id);
    const isGas = selected?.is_gas ?? false;
    const isRefill = isGas && !data.new_stock;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post('/inventories', {
            preserveScroll: true,
            // Keep the supplier and date — purchases are usually entered in
            // batches from one invoice — but clear what differs per product.
            onSuccess: () =>
                reset('catalogue_id', 'filled_quantity', 'empty_quantity', 'shell_unit_cost', 'gas_unit_cost', 'quantity', 'unit_cost'),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add inventory" />

            <div className="px-4 py-6">
                <Button variant="outline" asChild className="mb-10 shrink-0 self-start">
                    <Link href="/inventories">
                        <ArrowLeft className="mr-1 size-4" />
                        Back to Inventory
                    </Link>
                </Button>

                <div className="mt-6 grid gap-10 lg:grid-cols-[minmax(0,50rem)_minmax(0,1fr)]">
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="catalogue_id">Product</Label>

                            {items.length > 0 ? (
                                <Select value={data.catalogue_id} onValueChange={(value) => setData('catalogue_id', value)}>
                                    <SelectTrigger id="catalogue_id">
                                        <SelectValue placeholder="Select a product" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {items.map((item) => (
                                            <SelectItem key={item.id} value={String(item.id)}>
                                                {item.display_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : (
                                <p className="text-muted-foreground rounded-md border border-dashed px-3 py-2 text-sm">
                                    No products yet.{' '}
                                    <Link href="/catalogue/setup" className="underline underline-offset-4">
                                        Set up the catalogue
                                    </Link>{' '}
                                    first.
                                </p>
                            )}

                            <InputError message={errors.catalogue_id} />
                        </div>

                        {isGas && (
                            <div className="flex items-center justify-between gap-4 rounded-md border p-4">
                                <div className="grid gap-1">
                                    <Label htmlFor="new_stock" className="font-medium">
                                        New cylinders
                                    </Label>
                                    <p className="text-muted-foreground text-sm">Turn it off for a refill cylinders</p>
                                </div>

                                <Switch
                                    id="new_stock"
                                    checked={data.new_stock}
                                    onCheckedChange={(checked) => setData('new_stock', checked)}
                                    className="shrink-0"
                                />
                            </div>
                        )}

                        {/* Gas is bought as shells plus gas, each costed on its
                            own; plain goods are one quantity at one cost. */}
                        {isGas ? (
                            <div className="grid gap-6 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="filled_quantity">Filled cylinders</Label>
                                    <Input
                                        id="filled_quantity"
                                        type="text"
                                        min="0"
                                        value={data.filled_quantity}
                                        onChange={(e) => {
                                            setData('filled_quantity', e.target.value)
                                            setData('empty_quantity', e.target.value)
                                        }}
                                        placeholder="0"
                                    />
                                    <InputError message={errors.filled_quantity} />
                                </div>

                                <div className="grid gap-2 relative">
                                    <Label htmlFor="empty_quantity">{isRefill ? 'Empties sent' : 'Empty cylinders'}</Label>
                                    <Input
                                        id="empty_quantity"
                                        type="text"
                                        min="0"
                                        value={data.empty_quantity !== '' ? data.empty_quantity : data.filled_quantity}
                                        onChange={(e) => setData('empty_quantity', e.target.value)}
                                        placeholder="0"
                                    />
                                    <InputError className="text-xs absolute bottom-[-35px]" message={errors.empty_quantity} />
                                </div>

                                {/* A refill buys gas only — the shells are
                                    already owned, so there is no shell cost. */}
                                {!isRefill && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="shell_unit_cost">Shell cost (each)</Label>
                                        <Input
                                            id="shell_unit_cost"
                                            type="text"
                                            step="0.01"
                                            min="0"
                                            value={data.shell_unit_cost}
                                            onChange={(e) => setData('shell_unit_cost', e.target.value)}
                                            placeholder="0.00"
                                        />
                                        <InputError message={errors.shell_unit_cost} />
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="gas_unit_cost">Gas cost (each)</Label>
                                    <Input
                                        id="gas_unit_cost"
                                        type="text"
                                        step="0.01"
                                        min="0"
                                        value={data.gas_unit_cost}
                                        onChange={(e) => setData('gas_unit_cost', e.target.value)}
                                        placeholder="0.00"
                                    />
                                    <InputError message={errors.gas_unit_cost} />
                                </div>
                            </div>
                        ) : (
                            <div className="grid gap-6 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="quantity">Quantity</Label>
                                    <Input
                                        id="quantity"
                                        type="text"
                                        min="1"
                                        value={data.quantity}
                                        onChange={(e) => setData('quantity', e.target.value)}
                                        placeholder="0"
                                    />
                                    <InputError message={errors.quantity} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="unit_cost">Unit cost</Label>
                                    <Input
                                        id="unit_cost"
                                        type="text"
                                        step="0.01"
                                        min="0"
                                        value={data.unit_cost}
                                        onChange={(e) => setData('unit_cost', e.target.value)}
                                        placeholder="0.00"
                                    />
                                    <InputError message={errors.unit_cost} />
                                </div>
                            </div>
                        )}

                        <div className="grid gap-6 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="transport_cost">
                                    Transport <span className="text-muted-foreground font-normal">(optional)</span>
                                </Label>
                                <Input
                                    id="transport_cost"
                                    type="text"
                                    step="0.01"
                                    min="0"
                                    value={data.transport_cost}
                                    onChange={(e) => setData('transport_cost', e.target.value)}
                                    placeholder="0.00"
                                />
                                <p className="text-muted-foreground text-xs">For the whole consignment, not per unit.</p>
                                <InputError message={errors.transport_cost} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="other_cost">
                                    Other costs <span className="text-muted-foreground font-normal">(optional)</span>
                                </Label>
                                <Input
                                    id="other_cost"
                                    type="text"
                                    step="0.01"
                                    min="0"
                                    value={data.other_cost}
                                    onChange={(e) => setData('other_cost', e.target.value)}
                                    placeholder="0.00"
                                />
                                <InputError message={errors.other_cost} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="purchased_at">Purchase date</Label>
                            <Input
                                className="block"
                                id="purchased_at"
                                type="date"
                                value={data.purchased_at}
                                onChange={(e) => setData('purchased_at', e.target.value)}
                                required
                            />
                            <InputError message={errors.purchased_at} />
                        </div>

                        <div className="grid gap-6 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="supplier">
                                    Supplier <span className="text-muted-foreground font-normal">(optional)</span>
                                </Label>
                                <Input
                                    id="supplier"
                                    value={data.supplier}
                                    onChange={(e) => setData('supplier', e.target.value)}
                                    placeholder="Supplier name"
                                />
                                <InputError message={errors.supplier} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="invoice_ref">
                                    Invoice ref <span className="text-muted-foreground font-normal">(optional)</span>
                                </Label>
                                <Input
                                    id="invoice_ref"
                                    value={data.invoice_ref}
                                    onChange={(e) => setData('invoice_ref', e.target.value)}
                                    placeholder="Invoice number"
                                />
                                <InputError message={errors.invoice_ref} />
                            </div>
                        </div>

                        <Button disabled={processing || !data.catalogue_id}>Record purchase</Button>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
