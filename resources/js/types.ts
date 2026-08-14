export type RoleAssignment = {
    name: string;
    slug: string;
    module: string;
    zone_id: number | null;
    center_id: number | null;
    is_primary: boolean;
};

export type AuthUser = {
    id: number;
    name: string;
    email: string;
    roles: RoleAssignment[];
    permissions: string[];
};

export type PageProps<T = Record<string, unknown>> = T & {
    auth: { user: AuthUser | null };
    flash: { success?: string | null; error?: string | null; completionReport?: Record<string, unknown> | null; newBadges?: number[] };
};
