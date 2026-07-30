import { Badge } from '@/components/ui/badge';
import { type CatalogueItem } from '@/types/catalogue';
import { TriangleAlert } from 'lucide-react';

/**
 * A single catalogue item as a card, for narrow screens where the table's
 * five columns become unreadable.
 */
export default function CatalogueItemCard({ item }: { item: CatalogueItem }) {
    return (
        <li className="rounded-lg border p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate font-medium">{item.brand_name}</p>
                    <p className="text-muted-foreground mt-0.5 text-sm">
                        {item.type_label} - {item.weight}kg
                    </p>
                </div>

                {item.has_negative_stock && (
                    <Badge variant="destructive" className="shrink-0 gap-1">
                        <TriangleAlert className="size-3" />
                        Check stock
                    </Badge>
                )}
            </div>

            <dl className="mt-4 flex gap-6 text-sm">
                <div>
                    <dt className="text-muted-foreground text-xs">Filled</dt>
                    <dd className={`mt-0.5 tabular-nums ${item.filled_stock < 0 ? 'text-destructive font-medium' : 'font-medium'}`}>
                        {item.filled_stock}
                    </dd>
                </div>

                {/* Only returnable items track empty shells. */}
                {item.is_returnable && (
                    <div>
                        <dt className="text-muted-foreground text-xs">Empty</dt>
                        <dd className={`mt-0.5 tabular-nums ${item.empty_stock < 0 ? 'text-destructive font-medium' : 'font-medium'}`}>
                            {item.empty_stock}
                        </dd>
                    </div>
                )}
            </dl>
        </li>
    );
}
