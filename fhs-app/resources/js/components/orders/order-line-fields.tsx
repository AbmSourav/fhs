import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type SellableItem, type TransactionTypeOption } from '@/types/order';
import { Trash2 } from 'lucide-react';

export interface OrderLineValues {
    catalogue_id: string;
    transaction_type: string;
    returned_catalogue_id: string;
    quantity: string;
    /** The gas price when a shell is priced separately, otherwise the whole price. */
    unit_price: string;
    /** Only entered when the customer buys the cylinder outright. */
    cylinder_price: string;
}

interface OrderLineFieldsProps {
    line: OrderLineValues;
    index: number;
    items: SellableItem[];
    transactionTypes: TransactionTypeOption[];
    errors: Record<string, string | undefined>;
    onChange: (index: number, field: keyof OrderLineValues, value: string) => void;
    onRemove: (index: number) => void;
    /** The last remaining line cannot be removed — an order needs a product. */
    canRemove: boolean;
}

/** One product within a sale. */
export default function OrderLineFields({
    line,
    index,
    items,
    transactionTypes,
    errors,
    onChange,
    onRemove,
    canRemove,
}: OrderLineFieldsProps) {
    const selected = items.find((item) => String(item.id) === line.catalogue_id);
    const type = transactionTypes.find((option) => option.value === line.transaction_type);

    // A cross-brand swap is only possible when the transaction takes a shell
    // back and the product is one that has shells.
    const canReturnShell = Boolean(type?.returns_shell && selected?.is_returnable);

    // Buying a cylinder outright charges for the shell and its gas at once, so
    // the two are priced separately.
    const sellsShell = Boolean(type?.sells_shell);

    // Only returnable products can come back as empties.
    const returnableItems = items.filter((item) => item.is_returnable);

    const unitPrice = (Number(line.unit_price) || 0) + (sellsShell ? Number(line.cylinder_price) || 0 : 0);
    const lineTotal = (Number(line.quantity) || 0) * unitPrice;

    return (
        <li className="rounded-md border p-4">
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-medium">Product {index + 1}</p>

                {canRemove && (
                    <Button type="button" variant="ghost" size="sm" className="h-7 gap-1 p-2" onClick={() => onRemove(index)}>
                        <Trash2 className="size-3" />
                        Remove
                    </Button>
                )}
            </div>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor={`catalogue_id_${index}`}>Product</Label>
                    <Select value={line.catalogue_id} onValueChange={(value) => onChange(index, 'catalogue_id', value)}>
                        <SelectTrigger id={`catalogue_id_${index}`}>
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
                    <InputError message={errors[`items.${index}.catalogue_id`]} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor={`transaction_type_${index}`}>Sale type</Label>
                    <Select value={line.transaction_type} onValueChange={(value) => onChange(index, 'transaction_type', value)}>
                        <SelectTrigger id={`transaction_type_${index}`}>
                            <SelectValue placeholder="Select a type" />
                        </SelectTrigger>
                        <SelectContent>
                            {transactionTypes.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors[`items.${index}.transaction_type`]} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor={`quantity_${index}`}>Quantity</Label>
                    <Input
                        id={`quantity_${index}`}
                        type="text"
                        value={line.quantity}
                        onChange={(e) => onChange(index, 'quantity', e.target.value)}
                        placeholder="1"
                    />
                    <InputError message={errors[`items.${index}.quantity`]} />
                </div>

                {/* Buying a cylinder outright charges for the shell and the gas
                    inside it, so the two are priced separately. */}
                <div className="grid gap-2">
                    <Label htmlFor={`unit_price_${index}`}>{sellsShell ? 'Gas price (each)' : 'Price (each)'}</Label>
                    <Input
                        id={`unit_price_${index}`}
                        type="text"
                        value={line.unit_price}
                        onChange={(e) => onChange(index, 'unit_price', e.target.value)}
                        placeholder="0.00"
                    />
                    <InputError message={errors[`items.${index}.unit_price`]} />
                </div>

                {sellsShell && (
                    <div className="grid gap-2">
                        <Label htmlFor={`cylinder_price_${index}`}>Cylinder price (each)</Label>
                        <Input
                            id={`cylinder_price_${index}`}
                            type="text"
                            value={line.cylinder_price}
                            onChange={(e) => onChange(index, 'cylinder_price', e.target.value)}
                            placeholder="0.00"
                        />
                        <InputError message={errors[`items.${index}.cylinder_price`]} />
                    </div>
                )}

                {/* A customer may hand back another brand's empty. The shell
                    lands on that brand's stock, not the one sold. */}
                {canReturnShell && (
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor={`returned_catalogue_id_${index}`}>
                            Empty returned <span className="text-muted-foreground font-normal">(if a different brand)</span>
                        </Label>
                        <Select
                            value={line.returned_catalogue_id}
                            onValueChange={(value) => onChange(index, 'returned_catalogue_id', value)}
                        >
                            <SelectTrigger id={`returned_catalogue_id_${index}`}>
                                <SelectValue placeholder="Same as sold" />
                            </SelectTrigger>
                            <SelectContent>
                                {returnableItems.map((item) => (
                                    <SelectItem key={item.id} value={String(item.id)}>
                                        {item.display_name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}
            </div>

            {lineTotal > 0 && (
                <p className="text-muted-foreground mt-4 border-t pt-3 text-sm">
                    Line total <span className="text-foreground ml-1 font-medium tabular-nums">{lineTotal.toFixed(2)}</span>
                </p>
            )}
        </li>
    );
}
