import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BarChart3,
    Baby,
    BellRing,
    Building2,
    CalendarDays,
    ChevronRight,
    ClipboardCheck,
    FileText,
    Home,
    Library,
    LifeBuoy,
    LogOut,
    Map,
    MapPinned,
    Megaphone,
    Menu,
    MessageSquareQuote,
    MoreHorizontal,
    Network,
    PackageOpen,
    Settings,
    ShieldCheck,
    StickyNote,
    Target,
    Upload,
    UserRoundCheck,
    Users,
    UsersRound,
    X,
    type LucideIcon,
} from 'lucide-react';
import type { PageProps } from '../types';
import { useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react';

const SIDEBAR_SCROLL_KEY = 'happy-family:desktop-sidebar-scroll';

type NavItem = { label: string; shortLabel?: string; href: string; icon: LucideIcon; permission?: string | null; permissionsAny?: string[]; roleSlugs?: string[] };

const navigation: NavItem[] = [
    { label: 'Dashboard', shortLabel: 'Home', href: '/', icon: Home, permission: null },
    { label: 'Zones', href: '/admin/zones', icon: MapPinned, permission: 'manage_zones' },
    { label: 'Centers', href: '/admin/centers', icon: Building2, permission: 'view_center' },
    { label: 'Users', href: '/admin/users', icon: Users, permissionsAny: ['manage_users', 'reset_user_passwords'] },
    { label: 'Families', href: '/registration/families', icon: UsersRound, permission: 'register_family' },
    { label: 'Karyakars', href: '/registration/karyakars', icon: UserRoundCheck, permission: 'register_karyakar' },
    { label: 'SMVS Import', href: '/registration/imports', icon: Upload, permission: 'register_family' },
    { label: 'Groups', href: '/assignments/groups', icon: Network, permission: 'view_own_assignments' },
    { label: 'Area / Society', href: '/assignments/areas', icon: Map, permission: 'assign_area_society' },
    { label: 'Targets', href: '/assignments/targets', icon: Target, permission: 'assign_target' },
    { label: 'My Target', shortLabel: 'Target', href: '/field/my-target', icon: Target, permission: 'mark_home_visit', roleSlugs: ['karyakar', 'super_admin'] },
    { label: 'Reminders / Alerts', shortLabel: 'Alerts', href: '/field/reminders', icon: BellRing, permission: 'view_own_assignments', roleSlugs: ['karyakar', 'super_admin', 'bn_karyalay_admin', 'zonal_admin', 'center_admin', 'computer_op'] },
    { label: 'Bal Dashboard', shortLabel: 'Bal', href: '/bal-pravruti', icon: Baby, permission: 'access_bal_pravruti' },
    { label: 'Bal Groups', href: '/bal-pravruti/groups', icon: UsersRound, permission: 'access_bal_pravruti' },
    { label: 'Bal Completion', href: '/bal-pravruti/completions', icon: ClipboardCheck, permission: 'submit_bal_completion', roleSlugs: ['sanchalak'] },
    { label: 'Bal Analysis', href: '/bal-pravruti/analysis', icon: BarChart3, permission: 'view_bal_analysis' },
    { label: 'Analysis', href: '/monitoring/analysis', icon: BarChart3, permission: 'view_reports_analysis' },
    { label: 'Reports', shortLabel: 'Reports', href: '/monitoring/reports', icon: FileText, permission: 'view_reports_analysis' },
    { label: 'Announcements', href: '/support/announcements', icon: Megaphone, permission: 'view_announcements' },
    { label: 'Family Time', href: '/support/family-time', icon: CalendarDays, permission: 'view_family_time' },
    { label: 'Shared Content', href: '/support/content', icon: Library, permission: 'view_shared_content' },
    { label: 'Testimonials', href: '/support/testimonials', icon: MessageSquareQuote, permission: 'view_testimonials' },
    { label: 'Inventory', href: '/support/inventory', icon: PackageOpen, permission: 'view_inventory' },
    { label: 'Sticky Notes', href: '/support/sticky-notes', icon: StickyNote, permission: 'use_sticky_notes' },
    { label: 'Correction Requests', href: '/support/corrections', icon: ClipboardCheck, permission: 'submit_correction_request' },
    { label: 'Contact Support', href: '/support/contact', icon: LifeBuoy, permission: 'contact_support' },
    { label: 'Audit Logs', href: '/admin/audit-logs', icon: Activity, permission: 'view_audit_logs' },
    { label: 'Settings', href: '/admin/settings', icon: Settings, permission: 'manage_master_data' },
];

const preferredMobileTabs = ['/', '/field/my-target', '/assignments/groups', '/monitoring/reports', '/bal-pravruti'];

export default function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    const page = usePage<PageProps>();
    const { auth, flash } = page.props;
    const user = auth.user!;
    const role = user.roles.find((item) => item.is_primary) ?? user.roles[0];
    const isSuperAdmin = user.roles.some((assigned) => assigned.slug === 'super_admin');
    const allowed = (permission: string | null) => !permission || user.permissions.includes(permission) || isSuperAdmin;
    const visible = (item: NavItem) => {
        const permissionAllowed = item.permissionsAny?.length
            ? item.permissionsAny.some((permission) => allowed(permission))
            : allowed(item.permission ?? null);
        const roleAllowed = !item.roleSlugs?.length || item.roleSlugs.some((slug) => user.roles.some((assigned) => assigned.slug === slug));
        return permissionAllowed && roleAllowed;
    };
    const currentPath = page.url.split('?')[0].replace(/\/$/, '') || '/';
    const visibleNavigation = navigation.filter(visible);
    const matchesPath = (href: string) => {
        const normalizedHref = href.replace(/\/$/, '') || '/';
        if (normalizedHref === '/') return currentPath === '/';
        return currentPath === normalizedHref || currentPath.startsWith(`${normalizedHref}/`);
    };
    const activeHref = visibleNavigation
        .filter((item) => matchesPath(item.href))
        .sort((a, b) => b.href.length - a.href.length)[0]?.href;
    const isActive = (href: string) => href === activeHref;

    const mobileTabs = useMemo(() => {
        const chosen = preferredMobileTabs
            .map((href) => visibleNavigation.find((item) => item.href === href))
            .filter((item): item is NavItem => Boolean(item));
        for (const item of visibleNavigation) {
            if (chosen.length >= 4) break;
            if (!chosen.some((existing) => existing.href === item.href)) chosen.push(item);
        }
        return chosen.slice(0, 4);
    }, [visibleNavigation]);

    const sidebarRef = useRef<HTMLElement | null>(null);
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    useLayoutEffect(() => {
        const sidebar = sidebarRef.current;
        if (!sidebar) return;
        try {
            const stored = Number(window.sessionStorage.getItem(SIDEBAR_SCROLL_KEY) ?? '0');
            if (Number.isFinite(stored) && stored > 0) {
                window.requestAnimationFrame(() => {
                    sidebar.scrollTop = stored;
                });
            }
        } catch {
            // Storage can be unavailable in hardened/private browser contexts; scrolling still works.
        }
    }, []);

    useEffect(() => {
        setMobileMenuOpen(false);
    }, [page.url]);

    // Add semantic labels before paint so desktop tables become readable mobile cards without a label flicker.
    useLayoutEffect(() => {
        document.querySelectorAll<HTMLTableElement>('.hf-mobile-table').forEach((table) => {
            const headers = Array.from(table.querySelectorAll('thead th')).map((header) => header.textContent?.trim() ?? '');
            table.querySelectorAll('tbody tr').forEach((row) => {
                Array.from(row.children).forEach((cell, index) => {
                    if (!(cell instanceof HTMLTableCellElement) || cell.colSpan > 1) return;
                    cell.dataset.label = headers[index] ?? '';
                });
            });
        });
    });

    useEffect(() => {
        if (!mobileMenuOpen) return;
        const oldOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setMobileMenuOpen(false);
        };
        window.addEventListener('keydown', closeOnEscape);
        return () => {
            document.body.style.overflow = oldOverflow;
            window.removeEventListener('keydown', closeOnEscape);
        };
    }, [mobileMenuOpen]);

    const rememberSidebarScroll = () => {
        const sidebar = sidebarRef.current;
        if (!sidebar) return;
        try {
            window.sessionStorage.setItem(SIDEBAR_SCROLL_KEY, String(sidebar.scrollTop));
        } catch {
            // Keep navigation functional even when sessionStorage is unavailable.
        }
    };

    const userInitial = user.name.trim().charAt(0).toUpperCase() || 'U';
    const moreActive = Boolean(activeHref && !mobileTabs.some((item) => item.href === activeHref));

    return (
        <div className="hf-shell lg:grid lg:h-screen lg:grid-cols-[260px_1fr] lg:items-start lg:overflow-hidden">
            <aside
                ref={sidebarRef}
                onScroll={rememberSidebarScroll}
                className="hf-sidebar hf-desktop-sidebar min-h-screen p-5 lg:sticky lg:top-0 lg:h-screen lg:min-h-0 lg:overflow-y-auto lg:overscroll-contain"
            >
                <div className="hf-brand rounded-2xl p-4 mb-6">
                    <div className="text-xs uppercase tracking-[.18em] opacity-80">SMVS</div>
                    <div className="text-xl font-extrabold mt-1">Happy Family</div>
                    <div className="text-xs mt-1 opacity-80">Stronger Families, Stronger Society</div>
                </div>
                <nav className="space-y-1">
                    {visibleNavigation.map((item) => {
                        const Icon = item.icon;
                        const active = isActive(item.href);
                        return <Link
                            key={item.href}
                            href={item.href}
                            onBefore={rememberSidebarScroll}
                            aria-current={active ? 'page' : undefined}
                            className={`flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition ${active ? 'bg-white/18 font-extrabold text-white shadow-sm ring-1 ring-white/15' : 'text-white/95 hover:bg-white/10'}`}
                        ><Icon size={18}/>{item.label}</Link>;
                    })}
                </nav>
                <div className="mt-8 border-t border-white/15 pt-4 pb-2 text-xs opacity-85">
                    <div className="font-semibold">{user.name}</div>
                    <div className="mt-1">{role?.name ?? 'User'}</div>
                    <Link href="/logout" method="post" as="button" className="mt-4 flex items-center gap-2 rounded-lg bg-white/10 px-3 py-2"><LogOut size={15}/> Logout</Link>
                </div>
            </aside>

            <main className="hf-main min-w-0 lg:h-screen lg:overflow-y-auto lg:overscroll-contain">
                <header className="hf-desktop-header border-b border-[#eadff0] bg-white/90 px-4 py-4 backdrop-blur md:px-8 items-center justify-between gap-4">
                    <div>
                        <div className="text-xs font-semibold text-[#7b5f87]">SMVS Happy Family Portal</div>
                        <h1 className="text-xl font-extrabold text-[#351342]">{title}</h1>
                    </div>
                    <div className="flex items-center gap-2 text-xs font-semibold text-[#5d3b6a]"><ShieldCheck size={17}/> Role-scoped access</div>
                </header>

                <header className="hf-mobile-appbar lg:hidden">
                    <button type="button" className="hf-icon-btn" onClick={() => setMobileMenuOpen(true)} aria-label="Open navigation menu">
                        <Menu size={22}/>
                    </button>
                    <div className="min-w-0 flex-1">
                        <div className="hf-mobile-kicker">SMVS Happy Family</div>
                        <h1 className="hf-mobile-title">{title}</h1>
                    </div>
                    <button type="button" className="hf-avatar" onClick={() => setMobileMenuOpen(true)} aria-label="Open profile and menu">{userInitial}</button>
                </header>

                <div className="hf-page-content p-4 md:p-8">
                    {flash.success && <div className="mb-4 rounded-xl border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-800">{flash.success}</div>}
                    {flash.error && <div className="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800">{flash.error}</div>}
                    {children}
                </div>
            </main>

            <nav className="hf-mobile-bottom-nav lg:hidden" aria-label="Primary mobile navigation">
                {mobileTabs.map((item) => {
                    const Icon = item.icon;
                    const active = isActive(item.href);
                    return <Link key={item.href} href={item.href} className={`hf-mobile-tab ${active ? 'is-active' : ''}`} aria-current={active ? 'page' : undefined}>
                        <span className="hf-mobile-tab-icon"><Icon size={21}/></span>
                        <span>{item.shortLabel ?? item.label}</span>
                    </Link>;
                })}
                <button type="button" className={`hf-mobile-tab ${mobileMenuOpen || moreActive ? 'is-active' : ''}`} onClick={() => setMobileMenuOpen(true)} aria-label="More navigation options">
                    <span className="hf-mobile-tab-icon"><MoreHorizontal size={22}/></span>
                    <span>More</span>
                </button>
            </nav>

            {mobileMenuOpen && <div className="hf-mobile-menu-layer lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation menu">
                <button type="button" className="hf-mobile-menu-backdrop" onClick={() => setMobileMenuOpen(false)} aria-label="Close navigation menu" />
                <section className="hf-mobile-menu-sheet">
                    <div className="hf-mobile-menu-handle" aria-hidden="true" />
                    <div className="hf-mobile-menu-head">
                        <div className="flex min-w-0 items-center gap-3">
                            <div className="hf-avatar hf-avatar-large">{userInitial}</div>
                            <div className="min-w-0">
                                <div className="truncate font-extrabold text-[#351342]">{user.name}</div>
                                <div className="truncate text-xs font-semibold text-[#7b6783]">{role?.name ?? 'User'} · {user.email}</div>
                            </div>
                        </div>
                        <button type="button" className="hf-icon-btn" onClick={() => setMobileMenuOpen(false)} aria-label="Close navigation menu"><X size={22}/></button>
                    </div>
                    <div className="hf-mobile-menu-scroll">
                        <div className="hf-mobile-menu-grid">
                            {visibleNavigation.map((item) => {
                                const Icon = item.icon;
                                const active = isActive(item.href);
                                return <Link key={item.href} href={item.href} className={`hf-mobile-menu-item ${active ? 'is-active' : ''}`} aria-current={active ? 'page' : undefined}>
                                    <span className="hf-mobile-menu-icon"><Icon size={20}/></span>
                                    <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                    <ChevronRight size={17} className="opacity-45"/>
                                </Link>;
                            })}
                        </div>
                        <Link href="/logout" method="post" as="button" className="hf-mobile-logout"><LogOut size={18}/> Logout</Link>
                    </div>
                </section>
            </div>}
        </div>
    );
}
