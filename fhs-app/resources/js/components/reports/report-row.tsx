// narrowSymbol gives the ৳ sign; the default for BDT is the "BDT" code.
const currency = new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
});

interface Props {
    label: string;
    value: number;
    hint?: string;
    /** Counts are plain numbers; everything else is money. */
    format?: 'currency' | 'number';
    /** A subtotal — set apart from the lines that make it up. */
    emphasis?: boolean;
    /** The bottom line, which is the figure a reader looks for first. */
    total?: boolean;
}

/** One labelled figure. */
export default function Row({ label, value, hint, format = 'currency', emphasis, total }: Props) {
    return (
        <div
            className={
                'flex items-baseline justify-between gap-4 border-b last:border-b-0 py-2 ' +
                (emphasis ? 'font-semibold' : '') +
                (total ? ' border-b-2' : '')
            }
        >
            <div className="min-w-0">
                <p className={total ? 'text-base' : 'text-sm'}>{label}</p>
                {hint && <p className="text-muted-foreground text-[10px]">{hint}</p>}
            </div>

            <p
                className={
                    'shrink-0 tabular-nums ' +
                    (total ? 'text-base ' : 'text-sm ') +
                    // Only the bottom line is tinted. Colouring every negative
                    // would make ordinary costs look like problems.
                    (total && value < 0 ? 'text-destructive' : '')
                }
            >
                {format === 'currency' ? currency.format(value) : value.toLocaleString('en-BD')}
            </p>
        </div>
    );
}
