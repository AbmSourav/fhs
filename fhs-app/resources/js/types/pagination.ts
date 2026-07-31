/** One page of a Laravel length-aware paginator. */
export interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

export interface PaginationLink {
    /** Null for the previous/next arrows when there is no such page. */
    url: string | null;
    label: string;
    active: boolean;
}
