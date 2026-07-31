import { Button } from '@/components/ui/button';
import { type PaginationLink } from '@/types/pagination';
import { Link } from '@inertiajs/react';

/**
 * Laravel labels its arrows with HTML entities. Only these two ever appear, so
 * they are swapped directly rather than parsing the label as HTML.
 */
function decodeEntities(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»').trim();
}

/**
 * Page links for a Laravel paginator.
 *
 * Renders nothing for a single page — a lone "1" button is noise.
 */
export default function PaginationNav({ links, className = '' }: { links: PaginationLink[]; className?: string }) {
    // Two of the links are always the previous/next arrows.
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav className={`flex flex-wrap items-center justify-center gap-1 ${className}`} aria-label="Pagination">
            {links.map((link, index) => {
                // Laravel sends the arrows as HTML entities.
                const label = decodeEntities(link.label);

                // A null url means there is no such page, so it stays put.
                if (!link.url) {
                    return (
                        <Button key={index} variant="outline" size="sm" disabled>
                            {label}
                        </Button>
                    );
                }

                return (
                    <Button key={index} variant={link.active ? 'default' : 'outline'} size="sm" asChild>
                        <Link href={link.url} preserveScroll>
                            {label}
                        </Link>
                    </Button>
                );
            })}
        </nav>
    );
}
