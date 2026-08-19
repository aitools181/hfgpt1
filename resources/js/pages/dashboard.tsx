import { Head, Link } from '@inertiajs/react';
import {
    Baby,
    BarChart3,
    BellRing,
    Building2,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Network,
    Sparkles,
    Target,
    Trophy,
    UserRoundCheck,
    Users,
    UsersRound,
    type LucideIcon,
} from 'lucide-react';
import type { CSSProperties } from 'react';
import AppLayout from '../layouts/app-layout';

type FieldSummary = { activeGroups:number; assignedFamilies:number; completedFamilies:number; pendingFamilies:number; openReminders:number };
type Summary = { zones:number; centers:number; users:number|null; families:number; members:number; karyakars:number; approvedKaryakars:number; groups:number; activeGroups:number; activeTargets:number; targetQuantity:number; targetCompletedQuantity:number; assignedFamilies:number; completedFamilies:number; pendingFamilies:number; completionPercentage:number; homeVisits:number; balCompletedFamilies:number; overallCompletedFamilies:number };
type Monitoring = { scopeLabel:string; femaleScopeLocked:boolean; centerPerformance:any[]; zonePerformance:any[]; genderDistribution:{label:string;value:number}[]; categoryDistribution:{label:string;value:number}[] };
type Props = { summary: Summary; fieldSummary:FieldSummary|null; monitoring:Monitoring; dashboardWarnings?:string[]; quickActions:{label:string;href:string}[]; foundationStatus: {name:string; status:string}[] };
type MetricCard = { label:string; value:number; icon:LucideIcon; hint:string };

export default function Dashboard({ summary, fieldSummary, monitoring, dashboardWarnings = [], quickActions, foundationStatus }: Props) {
    const cards: MetricCard[] = [
        { label: 'Centers', value: summary.centers, icon: Building2, hint: 'Active campaign locations' },
        { label: 'Families', value: summary.families, icon: UsersRound, hint: 'Registered family records' },
        { label: 'Members', value: summary.members, icon: Users, hint: 'Family members covered' },
        { label: 'Approved Karyakars', value: summary.approvedKaryakars, icon: UserRoundCheck, hint: 'Approved field workers' },
        { label: 'Active Groups', value: summary.activeGroups, icon: Network, hint: 'Groups in execution' },
        { label: 'Main Completed', value: summary.completedFamilies, icon: CheckCircle2, hint: 'Main activity completed' },
        { label: 'Bal Completed', value: summary.balCompletedFamilies, icon: Baby, hint: 'Bal activity completed' },
        { label: 'Overall Completed', value: summary.overallCompletedFamilies, icon: ClipboardCheck, hint: 'Main + Bal completion' },
    ];
    const ringStyle = { '--hf-progress': Math.max(0, Math.min(100, summary.completionPercentage)) } as CSSProperties;

    return <AppLayout title="Dashboard Overview"><Head title="Dashboard" />
        <section className="hf-card hf-dashboard-hero mb-5 overflow-hidden">
            <div className="hf-dashboard-hero-main p-5 text-white">
                <div className="relative z-[1] flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                    <div className="max-w-2xl">
                        <div className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-[10px] font-extrabold uppercase tracking-[.13em] text-white/80"><Sparkles size={13}/>{monitoring.scopeLabel}</div>
                        <h2 className="mt-3 text-2xl font-black sm:text-[28px]">Happy Family campaign overview</h2>
                        <p className="mt-1 max-w-xl text-sm leading-6 text-white/78">Role-scoped monitoring, field progress and the actions that need your attention.</p>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <Link href="/monitoring/analysis" className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-white px-3.5 py-2 text-sm font-extrabold text-[#5c1974] shadow-lg shadow-black/10"><BarChart3 size={16}/> Analysis</Link>
                            <Link href="/monitoring/reports" className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-white/20 bg-white/10 px-3.5 py-2 text-sm font-extrabold text-white backdrop-blur"><FileText size={16}/> Reports</Link>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 sm:flex-col sm:items-end">
                        <div className="hf-progress-ring" style={ringStyle}>
                            <div className="hf-progress-ring-value">{summary.completionPercentage}%<span className="hf-progress-ring-label">Main completion</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div className="hf-dashboard-summary grid grid-cols-2 gap-3 p-4 sm:grid-cols-4">
                <FieldStat label="Assigned" value={summary.assignedFamilies}/>
                <FieldStat label="Main Completed" value={summary.completedFamilies}/>
                <FieldStat label="Bal Completed" value={summary.balCompletedFamilies}/>
                <FieldStat label="Overall Completed" value={summary.overallCompletedFamilies}/>
            </div>
        </section>

        {dashboardWarnings.map((warning) => <div key={warning} className="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900 shadow-sm">{warning}</div>)}

        {monitoring.femaleScopeLocked && <div className="mb-5 rounded-2xl border border-fuchsia-200 bg-fuchsia-50 p-4 text-sm font-semibold text-fuchsia-900 shadow-sm">BN Karyalay dashboard analysis is Female-specific. Administrative access continues according to the BN Karyalay role.</div>}

        {fieldSummary && <section className="hf-card mb-5 overflow-hidden">
            <div className="flex flex-wrap items-start justify-between gap-3 px-5 pt-5">
                <div><div className="text-[10px] font-extrabold uppercase tracking-[.14em] text-[#96869b]">Field execution</div><h2 className="mt-1 text-xl font-black text-[#35263a]">My Happy Family work</h2><p className="mt-1 text-xs font-medium text-[#827386]">Your assigned workload and current follow-up status.</p></div>
                <Target size={22} className="text-[#70218f]"/>
            </div>
            <div className="grid grid-cols-2 gap-3 p-4 sm:grid-cols-5">
                <FieldStat label="Groups" value={fieldSummary.activeGroups}/>
                <FieldStat label="Assigned" value={fieldSummary.assignedFamilies}/>
                <FieldStat label="Completed by me" value={fieldSummary.completedFamilies}/>
                <FieldStat label="Group pending" value={fieldSummary.pendingFamilies}/>
                <FieldStat label="Reminders" value={fieldSummary.openReminders}/>
            </div>
            <div className="flex flex-wrap gap-2 px-4 pb-4"><Link href="/field/my-target" className="hf-btn"><Target size={17}/> Open My Target</Link><Link href="/field/reminders" className="hf-btn hf-btn-secondary"><BellRing size={17}/> Reminders / Alerts</Link></div>
        </section>}

        <div className="hf-kpi-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {cards.map(({label,value,icon:Icon,hint}) => <div className="hf-card hf-kpi-card p-5" key={label}>
                <div className="relative z-[1] flex items-start justify-between gap-3">
                    <div className="min-w-0"><div className="text-xs font-extrabold uppercase tracking-[.04em] text-[#8a7a8f]">{label}</div><div className="mt-2 text-3xl font-black text-[#4f1764]">{value}</div></div>
                    <span className="hf-kpi-icon"><Icon size={19}/></span>
                </div>
                <div className="relative z-[1] mt-3 text-[11px] font-semibold text-[#988a9d]">{hint}</div>
            </div>)}
        </div>

        {quickActions.length > 0 && <section className="hf-card mt-5 p-5">
            <div className="hf-dashboard-section-title"><span className="hf-dashboard-section-icon"><Target size={18}/></span><div><h2 className="font-extrabold">Quick actions</h2><p className="mt-0.5 text-xs font-medium text-[#8c7e91]">Shortcuts available for your current role.</p></div></div>
            <div className="mt-4 flex flex-wrap gap-2">{quickActions.map(action=><Link key={action.href} href={action.href} className="hf-btn hf-btn-secondary">{action.label}</Link>)}</div>
        </section>}

        <div className="mt-5 grid gap-5 xl:grid-cols-2">
            <Leaderboard title="Top Centers" rows={monitoring.centerPerformance} keyName="center"/>
            <Leaderboard title="Top Zones" rows={monitoring.zonePerformance} keyName="zone"/>
        </div>

        <div className="mt-5 grid gap-5 lg:grid-cols-[1.3fr_.7fr]">
            <section className="hf-card p-6"><div className="hf-dashboard-section-title"><span className="hf-dashboard-section-icon"><CheckCircle2 size={18}/></span><h2 className="text-lg font-extrabold">Development status</h2></div><div className="mt-4 space-y-3">{foundationStatus.map(i => <div key={i.name} className="flex justify-between gap-4 border-b border-[#f0ecf2] pb-3 text-sm"><span>{i.name}</span><span className="hf-badge">{i.status}</span></div>)}</div></section>
            <section className="hf-card p-6"><div className="hf-dashboard-section-title"><span className="hf-dashboard-section-icon"><ClipboardCheck size={18}/></span><h2 className="text-lg font-extrabold">Project workflow</h2></div><ol className="mt-4 space-y-3 text-sm text-[#5d5062]"><li><b>1.</b> Registration & Data - ready</li><li><b>2.</b> Group & Assignment - ready</li><li><b>3.</b> Field Execution - ready</li><li><b>4.</b> Monitoring & Analysis - ready</li><li><b>5.</b> Bal Pravruti - ready</li><li><b>6.</b> Wireframe support modules - ready</li><li><b>7.</b> Production hardening - ready</li></ol></section>
        </div>
    </AppLayout>;
}

function FieldStat({label,value}:{label:string;value:number}) {
    return <div className="hf-field-stat rounded-xl p-3 text-center"><div className="text-2xl font-black text-[#55186d]">{value}</div><div className="mt-1 text-[10px] font-extrabold uppercase tracking-[.055em] text-[#88798d]">{label}</div></div>;
}

function Leaderboard({title,rows,keyName}:{title:string;rows:any[];keyName:string}) {
    return <section className="hf-card p-5">
        <div className="hf-dashboard-section-title"><span className="hf-dashboard-section-icon"><Trophy size={18}/></span><h2 className="font-extrabold">{title}</h2></div>
        <div className="mt-4 space-y-2">{rows.length===0?<div className="rounded-xl bg-[#faf8fb] p-4 text-sm font-medium text-[#85788a]">No active assignment data yet.</div>:rows.map((r:any)=><div key={`${r.rank}-${r[keyName]}`} className="hf-leader-row flex items-center gap-3 rounded-xl p-3"><span className="flex h-8 w-8 items-center justify-center rounded-xl bg-[#efe4f3] font-black text-[#6a1b9a]">{r.rank}</span><div className="min-w-0 flex-1"><div className="truncate font-bold text-[#3f3244]">{r[keyName]}</div><div className="mt-0.5 text-xs text-[#85788a]">{r.completed} main + {r.bal_completed ?? 0} Bal completed / {r.assigned} main assigned</div></div><span className="rounded-lg bg-[#f6eff8] px-2 py-1 text-sm font-black text-[#5b1972]">{r.completion_percentage}%</span></div>)}</div>
    </section>;
}
