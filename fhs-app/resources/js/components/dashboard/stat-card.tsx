import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

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
    icon?: LucideIcon;
    /** Explains what the figure covers, under the value. */
    hint?: string;
    /** Tints the value: a loss reads red, money earned reads green. */
    tone?: 'neutral' | 'positive' | 'negative';
    /** Counts are plain numbers; everything else is money. */
    format?: 'currency' | 'number';
    /** A delta badge, or anything else that belongs under the figure. */
    children?: ReactNode;
}

const toneClass: Record<NonNullable<Props['tone']>, string> = {
    neutral: '',
    positive: 'text-emerald-600 dark:text-emerald-400',
    negative: 'text-destructive',
};

/** One figure on the dashboard. */
export default function StatCard({ label, value, icon: Icon, hint, tone = 'neutral', format = 'currency', children }: Props) {
    return (
        <div className="rounded-xl border p-3">
            <div className="flex items-center justify-between gap-2">
                <p className="text-muted-foreground text-xs">{label}</p>
                {Icon && <Icon className="text-muted-foreground size-4 shrink-0" />}
            </div>

            <p className={cn('mt-2 text-xl font-semibold tabular-nums', toneClass[tone])}>
                {format === 'currency' ? currency.format(value) : value.toLocaleString('en-BD')}
            </p>

            {hint && <p className="text-muted-foreground mt-1 text-[10px]">{hint}</p>}

            {children}
        </div>
    );
}
