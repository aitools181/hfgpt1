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

type NavSection = 'Overview' | 'Registration' | 'Execution' | 'Bal Pravruti' | 'Monitoring' | 'Support' | 'Administration';
type NavItem = {
    label: string;
    shortLabel?: string;
    href: string;
    icon: LucideIcon;
    section: NavSection;
    permission?: string | null;
    permissionsAny?: string[];
    roleSlugs?: string[];
};

const navigation: NavItem[] = [
    { label: 'Dashboard', shortLabel: 'Home', href: '/', icon: Home, section: 'Overview', permission: null },
    { label: 'Families', href: '/registration/families', icon: UsersRound, section: 'Registration', permission: 'register_family' },
    { label: 'Karyakars', href: '/registration/karyakars', icon: UserRoundCheck, section: 'Registration', permission: 'register_karyakar' },
    { label: 'SMVS Import', href: '/registration/imports', icon: Upload, section: 'Registration', permission: 'register_family' },
    { label: 'Groups', href: '/assignments/groups', icon: Network, section: 'Execution', permission: 'view_own_assignments' },
    { label: 'Area / Society', href: '/assignments/areas', icon: Map, section: 'Execution', permission: 'assign_area_society' },
    { label: 'Targets', href: '/assignments/targets', icon: Target, section: 'Execution', permission: 'assign_target' },
    { label: 'My Target', shortLabel: 'Target', href: '/field/my-target', icon: Target, section: 'Execution', permission: 'mark_home_visit', roleSlugs: ['karyakar', 'super_admin'] },
    { label: 'Reminders / Alerts', shortLabel: 'Alerts', href: '/field/reminders', icon: BellRing, section: 'Execution', permission: 'view_own_assignments', roleSlugs: ['karyakar', 'super_admin', 'bn_karyalay_admin', 'zonal_admin', 'center_admin', 'computer_op'] },
    { label: 'Bal Dashboard', shortLabel: 'Bal', href: '/bal-pravruti', icon: Baby, section: 'Bal Pravruti', permission: 'access_bal_pravruti' },
    { label: 'Bal Groups', href: '/bal-pravruti/groups', icon: UsersRound, section: 'Bal Pravruti', permission: 'access_bal_pravruti' },
    { label: 'Bal Completion', href: '/bal-pravruti/completions', icon: ClipboardCheck, section: 'Bal Pravruti', permission: 'submit_bal_completion', roleSlugs: ['sanchalak'] },
    { label: 'Bal Analysis', href: '/bal-pravruti/analysis', icon: BarChart3, section: 'Bal Pravruti', permission: 'view_bal_analysis' },
    { label: 'Analysis', href: '/monitoring/analysis', icon: BarChart3, section: 'Monitoring', permission: 'view_reports_analysis' },
    { label: 'Reports', shortLabel: 'Reports', href: '/monitoring/reports', icon: FileText, section: 'Monitoring', permission: 'view_reports_analysis' },
    { label: 'Announcements', href: '/support/announcements', icon: Megaphone, section: 'Support', permission: 'view_announcements' },
    { label: 'Family Time', href: '/support/family-time', icon: CalendarDays, section: 'Support', permission: 'view_family_time' },
    { label: 'Shared Content', href: '/support/content', icon: Library, section: 'Support', permission: 'view_shared_content' },
    { label: 'Testimonials', href: '/support/testimonials', icon: MessageSquareQuote, section: 'Support', permission: 'view_testimonials' },
    { label: 'Inventory', href: '/support/inventory', icon: PackageOpen, section: 'Support', permission: 'view_inventory' },
    { label: 'Sticky Notes', href: '/support/sticky-notes', icon: StickyNote, section: 'Support', permission: 'use_sticky_notes' },
    { label: 'Correction Requests', href: '/support/corrections', icon: ClipboardCheck, section: 'Support', permission: 'submit_correction_request' },
    { label: 'Contact Support', href: '/support/contact', icon: LifeBuoy, section: 'Support', permission: 'contact_support' },
    { label: 'Zones', href: '/admin/zones', icon: MapPinned, section: 'Administration', permission: 'manage_zones' },
    { label: 'Centers', href: '/admin/centers', icon: Building2, section: 'Administration', permission: 'view_center' },
    { label: 'Users', href: '/admin/users', icon: Users, section: 'Administration', permissionsAny: ['manage_users', 'reset_user_passwords'] },
    { label: 'Audit Logs', href: '/admin/audit-logs', icon: Activity, section: 'Administration', permission: 'view_audit_logs' },
    { label: 'Settings', href: '/admin/settings', icon: Settings, section: 'Administration', permission: 'manage_master_data' },
];

const sectionOrder: NavSection[] = ['Overview', 'Registration', 'Execution', 'Bal Pravruti', 'Monitoring', 'Support', 'Administration'];
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
    const activeItem = visibleNavigation
        .filter((item) => matchesPath(item.href))
        .sort((a, b) => b.href.length - a.href.length)[0];
    const activeHref = activeItem?.href;
    const isActive = (href: string) => href === activeHref;

    const groupedNavigation = sectionOrder
        .map((section) => ({ section, items: visibleNavigation.filter((item) => item.section === section) }))
        .filter((group) => group.items.length > 0);

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
    const ActiveIcon = activeItem?.icon ?? Home;

    return (
        <div className="hf-shell lg:grid lg:h-screen lg:grid-cols-[276px_1fr] lg:items-start lg:overflow-hidden">
            <aside
                ref={sidebarRef}
                onScroll={rememberSidebarScroll}
                className="hf-sidebar hf-desktop-sidebar min-h-screen lg:sticky lg:top-0 lg:h-screen lg:min-h-0 lg:overflow-y-auto lg:overscroll-contain"
            >
                <div className="hf-sidebar-top">
                    <div className="hf-sidebar-brand">
                        <img src="/app-icon.svg" alt="" className="hf-sidebar-logo" />
                        <div className="min-w-0">
                            <div className="hf-sidebar-brand-kicker">SMVS</div>
                            <div className="hf-sidebar-brand-title">Happy Family</div>
                            <div className="hf-sidebar-brand-subtitle">Family campaign portal</div>
                        </div>
                    </div>
                </div>

                <nav className="hf-sidebar-nav" aria-label="Main navigation">
                    {groupedNavigation.map((group) => <div className="hf-sidebar-group" key={group.section}>
                        <div className="hf-sidebar-section-label">{group.section}</div>
                        <div className="hf-sidebar-section-items">
                            {group.items.map((item) => {
                                const Icon = item.icon;
                                const active = isActive(item.href);
                                return <Link
                                    key={item.href}
                                    href={item.href}
                                    onBefore={rememberSidebarScroll}
                                    aria-current={active ? 'page' : undefined}
                                    className={`hf-sidebar-link ${active ? 'is-active' : ''}`}
                                >
                                    <span className="hf-sidebar-link-icon"><Icon size={18}/></span>
                                    <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                    {active && <span className="hf-sidebar-active-dot" aria-hidden="true"/>}
                                </Link>;
                            })}
                        </div>
                    </div>)}
                </nav>

                <div className="hf-sidebar-profile-wrap">
                    <div className="hf-sidebar-profile">
                        <div className="hf-avatar hf-avatar-large">{userInitial}</div>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-sm font-extrabold text-[#2f2134]">{user.name}</div>
                            <div className="mt-0.5 truncate text-[11px] font-semibold text-[#8b7b91]">{role?.name ?? 'User'}</div>
                        </div>
                        <Link href="/logout" method="post" as="button" className="hf-sidebar-logout" aria-label="Logout"><LogOut size={17}/></Link>
                    </div>
                </div>
            </aside>

            <main className="hf-main min-w-0 lg:h-screen lg:overflow-y-auto lg:overscroll-contain">
                <header className="hf-desktop-header items-center justify-between gap-5">
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="hf-header-icon"><ActiveIcon size={20}/></span>
                        <div className="min-w-0">
                            <div className="hf-header-kicker">SMVS Happy Family</div>
                            <h1 className="hf-header-title truncate">{title}</h1>
                        </div>
                    </div>
                    <div className="hf-header-account">
                        <div className="min-w-0 text-right">
                            <div className="max-w-56 truncate text-sm font-extrabold text-[#302235]">{user.name}</div>
                            <div className="mt-0.5 flex items-center justify-end gap-1 text-[11px] font-semibold text-[#817087]"><ShieldCheck size={13}/>{role?.name ?? 'User'}</div>
                        </div>
                        <div className="hf-avatar">{userInitial}</div>
                    </div>
                </header>

                <header className="hf-mobile-appbar lg:hidden">
                    <button type="button" className="hf-icon-btn" onClick={() => setMobileMenuOpen(true)} aria-label="Open navigation menu">
                        <Menu size={21}/>
                    </button>
                    <div className="min-w-0 flex-1">
                        <div className="hf-mobile-kicker">SMVS Happy Family</div>
                        <h1 className="hf-mobile-title">{title}</h1>
                    </div>
                    <button type="button" className="hf-avatar" onClick={() => setMobileMenuOpen(true)} aria-label="Open profile and menu">{userInitial}</button>
                </header>

                <div className="hf-page-content p-4 md:p-7 xl:p-8">
                    {flash.success && <div className="hf-alert hf-alert-success mb-4">{flash.success}</div>}
                    {flash.error && <div className="hf-alert hf-alert-error mb-4">{flash.error}</div>}
                    {children}
                </div>
            </main>

            <nav className="hf-mobile-bottom-nav lg:hidden" aria-label="Primary mobile navigation">
                {mobileTabs.map((item) => {
                    const Icon = item.icon;
                    const active = isActive(item.href);
                    return <Link key={item.href} href={item.href} className={`hf-mobile-tab ${active ? 'is-active' : ''}`} aria-current={active ? 'page' : undefined}>
                        <span className="hf-mobile-tab-icon"><Icon size={20}/></span>
                        <span className="truncate">{item.shortLabel ?? item.label}</span>
                    </Link>;
                })}
                <button type="button" className={`hf-mobile-tab ${mobileMenuOpen || moreActive ? 'is-active' : ''}`} onClick={() => setMobileMenuOpen(true)} aria-label="More navigation options">
                    <span className="hf-mobile-tab-icon"><MoreHorizontal size={21}/></span>
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
                                <div className="truncate font-extrabold text-[#302235]">{user.name}</div>
                                <div className="truncate text-xs font-semibold text-[#85758b]">{role?.name ?? 'User'} · {user.email}</div>
                            </div>
                        </div>
                        <button type="button" className="hf-icon-btn" onClick={() => setMobileMenuOpen(false)} aria-label="Close navigation menu"><X size={21}/></button>
                    </div>
                    <div className="hf-mobile-menu-scroll">
                        {groupedNavigation.map((group) => <div className="hf-mobile-menu-section" key={group.section}>
                            <div className="hf-mobile-menu-section-label">{group.section}</div>
                            <div className="hf-mobile-menu-grid">
                                {group.items.map((item) => {
                                    const Icon = item.icon;
                                    const active = isActive(item.href);
                                    return <Link key={item.href} href={item.href} className={`hf-mobile-menu-item ${active ? 'is-active' : ''}`} aria-current={active ? 'page' : undefined}>
                                        <span className="hf-mobile-menu-icon"><Icon size={19}/></span>
                                        <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                        <ChevronRight size={16} className="opacity-40"/>
                                    </Link>;
                                })}
                            </div>
                        </div>)}
                        <Link href="/logout" method="post" as="button" className="hf-mobile-logout"><LogOut size={18}/> Logout</Link>
                    </div>
                </section>
            </div>}
        </div>
    );
}
