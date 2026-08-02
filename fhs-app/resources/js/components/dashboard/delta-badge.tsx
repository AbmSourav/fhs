import { cn } from '@/lib/utils';
import { type Metric } from '@/types/dashboard';
import { ArrowDown, ArrowUp } from 'lucide-react';

interface Props {
    metric: Metric;
    /** Which way is good. Up for revenue, down for anything owed or spent. */
    goodDirection?: 'up' | 'down';
    /** What the comparison is against, e.g. "July 2026". */
    against: string;
}

/** How a figure moved since last month. */
export default function DeltaBadge({ metric, goodDirection = 'up', against }: Props) {
    // Two empty months have nothing to report, and a badge saying so is noise.
    if (metric.direction === 'flat' && metric.percent === null) {
        return null;
    }

    if (metric.direction === 'new') {
        return <p className="text-muted-foreground mt-2 text-xs">First activity — nothing in {against}</p>;
    }

    const isGood = metric.direction === goodDirection;
    const Arrow = metric.direction === 'up' ? ArrowUp : ArrowDown;

    return (
        <p className="mt-2 flex items-center gap-1 text-xs">
            {metric.direction !== 'flat' && (
                <>
                    {/* The arrow carries the meaning, so the figure still reads
                        correctly without relying on colour alone. */}
                    <Arrow className={cn('size-3 shrink-0', isGood ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive')} />
                    <span className={cn('font-medium tabular-nums', isGood ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive')}>
                        {Math.abs(metric.percent ?? 0)}%
                    </span>
                </>
            )}
            <span className="text-muted-foreground truncate">vs {against}</span>
        </p>
    );
}
