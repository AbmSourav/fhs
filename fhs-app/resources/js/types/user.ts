/**
 * A user account.
 *
 * Staff are the only users: founders and investors are given credentials by an
 * administrator out of band, and customers are records in the system rather
 * than accounts that sign in.
 */
export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    /**
     * Per-user capability overrides, holding the role among them.
     *
     * Null for an account with no explicit grants — including administrators,
     * whose standing comes from config rather than from this column.
     */
    permission: UserPermission | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

/** What is stored in `users.permission`. */
export interface UserPermission {
    /** "investor" or "founder"; absent on an account predating roles. */
    role?: string;
}

/** A role as offered by a select: the stored value, and what to show. */
export interface RoleOption {
    value: string;
    label: string;
}

/**
 * An account as the users page lists it.
 *
 * Flatter than the model: the role is resolved to a label server-side, and
 * administrator standing is a plain flag rather than something to work out from
 * an email against config.
 */
export interface ManagedUser {
    id: number;
    name: string;
    email: string;
    /** Null for an administrator, whose standing comes from config. */
    role: string | null;
    role_label: string | null;
    is_admin: boolean;
    verified: boolean;
    created_at: string;
}
