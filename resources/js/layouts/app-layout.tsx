import { Link, usePage } from '@inertiajs/react';
import { Activity, BarChart3, BellRing, Building2, FileText, Home, LogOut, Settings, ShieldCheck, Users, MapPinned, Upload, UserRoundCheck, UsersRound, Network, Map, Target, Baby, ClipboardCheck, Megaphone, CalendarDays, Library, MessageSquareQuote, PackageOpen, StickyNote, LifeBuoy } from 'lucide-react';
import type { PageProps } from '../types';
import type { ReactNode } from 'react';

const navigation = [
    { label: 'Dashboard', href: '/', icon: Home, permission: null },
    { label: 'Zones', href: '/admin/zones', icon: MapPinned, permission: 'manage_zones' },
    { label: 'Centers', href: '/admin/centers', icon: Building2, permission: 'view_center' },
    { label: 'Users', href: '/admin/users', icon: Users, permission: 'manage_users' },
    { label: 'Families', href: '/registration/families', icon: UsersRound, permission: 'register_family' },
    { label: 'Karyakars', href: '/registration/karyakars', icon: UserRoundCheck, permission: 'register_karyakar' },
    { label: 'SMVS Import', href: '/registration/imports', icon: Upload, permission: 'register_family' },
    { label: 'Groups', href: '/assignments/groups', icon: Network, permission: 'view_own_assignments' },
    { label: 'Area / Society', href: '/assignments/areas', icon: Map, permission: 'assign_area_society' },
    { label: 'Targets', href: '/assignments/targets', icon: Target, permission: 'assign_target' },
    { label: 'My Target', href: '/field/my-target', icon: Target, permission: 'mark_home_visit', roleSlugs: ['karyakar', 'super_admin'] },
    { label: 'Reminders / Alerts', href: '/field/reminders', icon: BellRing, permission: 'view_own_assignments' },
    { label: 'Bal Dashboard', href: '/bal-pravruti', icon: Baby, permission: 'access_bal_pravruti' },
    { label: 'Bal Groups', href: '/bal-pravruti/groups', icon: UsersRound, permission: 'access_bal_pravruti' },
    { label: 'Bal Completion', href: '/bal-pravruti/completions', icon: ClipboardCheck, permission: 'submit_bal_completion', roleSlugs: ['sanchalak'] },
    { label: 'Bal Analysis', href: '/bal-pravruti/analysis', icon: BarChart3, permission: 'view_bal_analysis' },
    { label: 'Analysis', href: '/monitoring/analysis', icon: BarChart3, permission: 'view_reports_analysis' },
    { label: 'Reports', href: '/monitoring/reports', icon: FileText, permission: 'view_reports_analysis' },
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

export default function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, flash } = usePage<PageProps>().props;
    const user = auth.user!;
    const role = user.roles.find((item) => item.is_primary) ?? user.roles[0];
    const isSuperAdmin = user.roles.some((assigned) => assigned.slug === 'super_admin');
    const allowed = (permission: string | null) => !permission || user.permissions.includes(permission) || isSuperAdmin;
    const visible = (item: typeof navigation[number]) => allowed(item.permission) && (!('roleSlugs' in item) || !item.roleSlugs || item.roleSlugs.some((slug) => user.roles.some((assigned) => assigned.slug === slug)));

    return (
        <div className="hf-shell lg:grid lg:grid-cols-[260px_1fr]">
            <aside className="hf-sidebar hf-desktop-sidebar min-h-screen p-5">
                <div className="hf-brand rounded-2xl p-4 mb-6">
                    <div className="text-xs uppercase tracking-[.18em] opacity-80">SMVS</div>
                    <div className="text-xl font-extrabold mt-1">Happy Family</div>
                    <div className="text-xs mt-1 opacity-80">Stronger Families, Stronger Society</div>
                </div>
                <nav className="space-y-1">
                    {navigation.filter(visible).map((item) => {
                        const Icon = item.icon;
                        return <Link key={item.href} href={item.href} className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm hover:bg-white/10"><Icon size={18}/>{item.label}</Link>;
                    })}
                </nav>
                <div className="mt-8 border-t border-white/15 pt-4 text-xs opacity-85">
                    <div className="font-semibold">{user.name}</div>
                    <div className="mt-1">{role?.name ?? 'User'}</div>
                    <Link href="/logout" method="post" as="button" className="mt-4 flex items-center gap-2 rounded-lg bg-white/10 px-3 py-2"><LogOut size={15}/> Logout</Link>
                </div>
            </aside>

            <main className="min-w-0">
                <header className="border-b border-[#eadff0] bg-white/90 px-4 py-4 backdrop-blur md:px-8 flex items-center justify-between gap-4">
                    <div>
                        <div className="text-xs font-semibold text-[#7b5f87]">SMVS Happy Family Portal</div>
                        <h1 className="text-xl font-extrabold text-[#351342]">{title}</h1>
                    </div>
                    <div className="flex items-center gap-2 text-xs font-semibold text-[#5d3b6a]"><ShieldCheck size={17}/> Role-scoped access</div>
                </header>

                <div className="p-4 md:p-8">
                    <div className="mb-4 flex flex-wrap gap-2 lg:hidden">
                        {navigation.filter(visible).map((item) => <Link key={item.href} href={item.href} className="hf-badge">{item.label}</Link>)}
                    </div>
                    {flash.success && <div className="mb-4 rounded-xl border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-800">{flash.success}</div>}
                    {flash.error && <div className="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800">{flash.error}</div>}
                    {children}
                </div>
            </main>
        </div>
    );
}
