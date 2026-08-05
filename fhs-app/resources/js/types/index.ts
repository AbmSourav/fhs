import { LucideIcon } from 'lucide-react';
// Imported as well as re-exported below: `export … from` does not bring the
// name into this file's scope, and Auth refers to it.
import { type User } from './user';

export interface Auth {
    // Typed non-null because every page using it sits behind auth. The server
    // does share null for a guest, so a page rendered outside the auth
    // middleware must not assume this.
    user: User;
    /** Convenience flag for admin-only UI; the server still authorises. */
    isAdmin: boolean;
    /** False for an investor: they read every page but change nothing. */
    canWrite: boolean;
    /** Administrators only — founders cannot create or remove accounts. */
    canManageUsers: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    /** One-request-only messages from the server, surfaced as toasts. */
    flash: Flash;
    /** The role enum, keyed by stored value: { investor: 'Investor', … }. */
    userRoles: Record<string, string>;
    [key: string]: unknown;
}

export interface Flash {
    success: string | null;
    error: string | null;
}

// User shapes live in ./user, re-exported here so `@/types` stays the one
// import path for the shared types a page reaches for.
export type { ManagedUser, RoleOption, User, UserPermission } from './user';
