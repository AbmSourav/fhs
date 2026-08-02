import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { businessToday } from '@/lib/datetime';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type PurchasableItem, type PurchaseFormValues } from '@/types/inventory';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { FormEventHandler } from 'react';

/**
 * Today in the `yyyy-mm-dd` shape a date input expects.
 *
 * Business time rather than the browser's, so the picker and the server agree
 * on what day it is.
 */
const today = businessToday;

interface InventoryFormProps {
    items: PurchasableItem[];
    /** Absent when adding; present when correcting an existing purchase. */
    purchase?: PurchaseFormValues;
    /** Which purchase table the record belongs to. Edit mode only. */
    kind?: string;
    /** Why this purchase can no longer be edited, when it cannot. */
    blockedReason?: string | null;
}

export default function InventoryForm({ items, purchase, kind, blockedReason }: InventoryFormProps) {
    const isEditing = purchase !== undefined;

    const { data, setData, post, patch, errors, processing, reset } = useForm({
        catalogue_id: purchase?.catalogue_id ?? '',
        supplier: purchase?.supplier ?? '',
        invoice_ref: purchase?.invoice_ref ?? '',
        purchased_at: purchase?.purchased_at ?? today(),
        // Whose empties were sent. Empty means cylinders were bought outright;
        // set to the same product for a like-for-like swap, or another product
        // when the supplier took a different brand back.
        swap_catalogue_id: purchase?.swap_catalogue_id ?? '',
        filled_quantity: purchase?.filled_quantity ?? '',
        empty_quantity: purchase?.empty_quantity ?? '',
        shell_unit_cost: purchase?.shell_unit_cost ?? '',
        gas_unit_cost: purchase?.gas_unit_cost ?? '',
        quantity: purchase?.quantity ?? '',
        unit_cost: purchase?.unit_cost ?? '',
        transport_cost: purchase?.transport_cost ?? '',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Inventory', href: '/inventories' },
        isEditing ? { title: 'Edit', href: '#' } : { title: 'Purchase', href: '/inventories/add' },
    ];

    // Which half of the form applies is a fact about the product, so it is read
    // from the chosen item rather than asked separately.
    const selected = items.find((item) => String(item.id) === data.catalogue_id);
    const isGas = selected?.is_gas ?? false;
    const isRefill = isGas && data.swap_catalogue_id !== '';

    // Only a returnable product has empties to send back.
    const returnableItems = items.filter((item) => item.is_returnable);

    // Toggling a swap on defaults to sending the same product's empties, which
    // is the common case; the picker below changes it to another brand.
    const toggleSwap = (isSwap: boolean) => setData('swap_catalogue_id', isSwap ? data.catalogue_id : '');

    const isLocked = Boolean(blockedReason);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (isLocked) {
            return;
        }

        if (isEditing) {
            // An edit appends a replacement row server-side and redirects to
            // the list, so there is nothing to reset here.
            patch(`/inventories/update/${kind}/${purchase.id}`, { preserveScroll: true });

            return;
        }

        post('/inventories', {
            preserveScroll: true,
            // Keep the supplier and date — purchases are usually entered in
            // batches from one invoice — but clear what differs per product.
            onSuccess: () =>
                reset(
                    'catalogue_id',
                    'swap_catalogue_id',
                    'filled_quantity',
                    'empty_quantity',
                    'shell_unit_cost',
                    'gas_unit_cost',
                    'quantity',
                    'unit_cost',
                ),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={isEditing ? 'Edit inventory' : 'Add inventory'} />

            <div className="px-4 py-6">
                <Button variant="outline" asChild className="mb-10 shrink-0 self-start">
                    <Link href="/inventories">
                        <ArrowLeft className="mr-1 size-4" />
                        Back to Inventory
                    </Link>
                </Button>

                {/* Corrections append a new record rather than overwriting, so
                    it is worth saying what saving will actually do. */}
                {isEditing && purchase && !isLocked && (
                    <div className="mb-6 rounded-md border border-dashed px-4 py-3">
                        <p className="text-sm font-medium">Correcting a recorded purchase</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            The original stays on record and its stock is reversed. {purchase.edits_used} of {purchase.edits_allowed}{' '}
                            corrections used.
                        </p>
                    </div>
                )}

                {isLocked && (
                    <div className="border-destructive/50 bg-destructive/5 mb-6 rounded-md border px-4 py-3">
                        <p className="text-sm font-medium">This purchase can no longer be corrected</p>
                        <p className="text-muted-foreground mt-1 text-sm">{blockedReason}</p>
                    </div>
                )}

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
                            <div className="rounded-md border p-4">
                                <div className="flex items-center justify-between gap-4">
                                    <div className="grid gap-1">
                                        <Label htmlFor="is_swap" className="font-medium">
                                            Swap
                                        </Label>
                                        <p className="text-muted-foreground text-sm">Empty cylinders sent, filled ones received. No new shells bought.</p>
                                    </div>

                                    <Switch
                                        id="is_swap"
                                        checked={isRefill}
                                        onCheckedChange={toggleSwap}
                                        disabled={!data.catalogue_id}
                                        className="shrink-0"
                                    />
                                </div>

                                {isRefill && (
                                    <div className="mt-4 grid gap-2 border-t pt-4">
                                        <Label htmlFor="swap_catalogue_id">Empties sent</Label>

                                        <Select
                                            value={data.swap_catalogue_id}
                                            onValueChange={(value) => setData('swap_catalogue_id', value)}
                                        >
                                            <SelectTrigger id="swap_catalogue_id">
                                                <SelectValue placeholder="Select a product" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {returnableItems.map((item) => (
                                                    <SelectItem key={item.id} value={String(item.id)}>
                                                        {item.display_name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>

                                        <p className="text-muted-foreground text-xs">
                                            Choose another brand if that is what was sent back.
                                        </p>

                                        <InputError message={errors.swap_catalogue_id} />
                                    </div>
                                )}
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
                                            if (isRefill === true) {
                                                setData('empty_quantity', e.target.value)
                                            }
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
                                        value={data.empty_quantity}
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
                            <p className="text-muted-foreground text-xs">
                                For the whole consignment, not per unit. Anything else spent goes in Other Expenses.
                            </p>
                            <InputError message={errors.transport_cost} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="purchased_at">Purchase date</Label>
                            <Input
                                className="block"
                                id="purchased_at"
                                type="date"
                                value={data.purchased_at}
                                onChange={(e) => setData('purchased_at', e.target.value)}
                                // Stops the picker offering a future date. The
                                // server rejects one regardless.
                                max={today()}
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

                        <div className="flex items-center gap-3">
                            <Button disabled={processing || !data.catalogue_id || isLocked}>
                                {isEditing ? 'Save correction' : 'Record purchase'}
                            </Button>

                            {isEditing && (
                                <Button variant="secondary" className="px-6" asChild>
                                    <Link href="/inventories">Cancel</Link>
                                </Button>
                            )}
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
