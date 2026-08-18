import { Head, Link } from '@inertiajs/react';
import { BarChart3, BellRing, FileText, Target, Trophy } from 'lucide-react';
import AppLayout from '../layouts/app-layout';

type FieldSummary = { activeGroups:number; assignedFamilies:number; completedFamilies:number; pendingFamilies:number; openReminders:number };
type Summary = { zones:number; centers:number; users:number|null; families:number; members:number; karyakars:number; approvedKaryakars:number; groups:number; activeGroups:number; activeTargets:number; targetQuantity:number; targetCompletedQuantity:number; assignedFamilies:number; completedFamilies:number; pendingFamilies:number; completionPercentage:number; homeVisits:number; balCompletedFamilies:number; overallCompletedFamilies:number };
type Monitoring = { scopeLabel:string; femaleScopeLocked:boolean; centerPerformance:any[]; zonePerformance:any[]; genderDistribution:{label:string;value:number}[]; categoryDistribution:{label:string;value:number}[] };
type Props = { summary: Summary; fieldSummary:FieldSummary|null; monitoring:Monitoring; dashboardWarnings?:string[]; quickActions:{label:string;href:string}[]; foundationStatus: {name:string; status:string}[] };
export default function Dashboard({ summary, fieldSummary, monitoring, dashboardWarnings = [], quickActions, foundationStatus }: Props) {
    const cards = [['Centers', summary.centers], ['Families', summary.families], ['Members', summary.members], ['Approved Karyakars', summary.approvedKaryakars], ['Active Groups', summary.activeGroups], ['Main Completed', summary.completedFamilies], ['Bal Completed', summary.balCompletedFamilies], ['Overall Completed', summary.overallCompletedFamilies]];
    return <AppLayout title="Dashboard Overview"><Head title="Dashboard" />
        <section className="hf-card mb-5 overflow-hidden"><div className="hf-brand p-5 text-white"><div className="text-xs font-bold uppercase tracking-[.16em] opacity-80">{monitoring.scopeLabel}</div><div className="mt-1 flex flex-wrap items-end justify-between gap-3"><div><h2 className="text-2xl font-black">Happy Family campaign overview</h2><p className="mt-1 text-sm opacity-85">Role-scoped monitoring, progress and quick actions.</p></div><div className="rounded-xl bg-white/10 px-4 py-3 text-right"><div className="text-3xl font-black">{summary.completionPercentage}%</div><div className="text-xs font-semibold opacity-85">Main campaign completion</div></div></div></div>
            <div className="grid grid-cols-2 gap-3 p-4 sm:grid-cols-4"><FieldStat label="Assigned" value={summary.assignedFamilies}/><FieldStat label="Main Completed" value={summary.completedFamilies}/><FieldStat label="Bal Completed" value={summary.balCompletedFamilies}/><FieldStat label="Overall Completed" value={summary.overallCompletedFamilies}/></div>
            <div className="flex flex-wrap gap-2 px-4 pb-4"><Link href="/monitoring/analysis" className="hf-btn inline-flex items-center gap-2"><BarChart3 size={17}/> Analysis</Link><Link href="/monitoring/reports" className="hf-btn hf-btn-secondary inline-flex items-center gap-2"><FileText size={17}/> Reports</Link></div>
        </section>

        {dashboardWarnings.map((warning) => <div key={warning} className="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">{warning}</div>)}

        {monitoring.femaleScopeLocked && <div className="mb-5 rounded-xl border border-fuchsia-200 bg-fuchsia-50 p-4 text-sm font-semibold text-fuchsia-900">BN Karyalay dashboard analysis is Female-specific. Administrative access continues according to the BN Karyalay role.</div>}

        {fieldSummary && <section className="hf-card mb-5 overflow-hidden"><div className="px-5 pt-5"><div className="text-xs font-bold uppercase tracking-[.16em] text-[#7b5f87]">Field execution</div><h2 className="mt-1 text-xl font-black">My Happy Family work</h2></div><div className="grid grid-cols-2 gap-3 p-4 sm:grid-cols-5"><FieldStat label="Groups" value={fieldSummary.activeGroups}/><FieldStat label="Assigned" value={fieldSummary.assignedFamilies}/><FieldStat label="Completed by me" value={fieldSummary.completedFamilies}/><FieldStat label="Group pending" value={fieldSummary.pendingFamilies}/><FieldStat label="Reminders" value={fieldSummary.openReminders}/></div><div className="flex flex-wrap gap-2 px-4 pb-4"><Link href="/field/my-target" className="hf-btn inline-flex items-center gap-2"><Target size={17}/> Open My Target</Link><Link href="/field/reminders" className="hf-btn hf-btn-secondary inline-flex items-center gap-2"><BellRing size={17}/> Reminders / Alerts</Link></div></section>}

        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{cards.map(([label,value]) => <div className="hf-card p-5" key={String(label)}><div className="text-sm font-semibold text-[#7a657f]">{label}</div><div className="mt-2 text-3xl font-black text-[#5f187c]">{value}</div></div>)}</div>

        {quickActions.length > 0 && <section className="hf-card mt-5 p-5"><h2 className="font-extrabold">Quick actions</h2><div className="mt-3 flex flex-wrap gap-2">{quickActions.map(action=><Link key={action.href} href={action.href} className="hf-btn hf-btn-secondary">{action.label}</Link>)}</div></section>}

        <div className="mt-5 grid gap-5 xl:grid-cols-2">
            <Leaderboard title="Top Centers" rows={monitoring.centerPerformance} keyName="center"/>
            <Leaderboard title="Top Zones" rows={monitoring.zonePerformance} keyName="zone"/>
        </div>

        <div className="grid gap-5 mt-5 lg:grid-cols-[1.3fr_.7fr]">
            <section className="hf-card p-6"><h2 className="text-lg font-extrabold">Development status</h2><div className="mt-4 space-y-3">{foundationStatus.map(i => <div key={i.name} className="flex justify-between gap-4 border-b border-[#f0e7f3] pb-3"><span>{i.name}</span><span className="hf-badge">{i.status}</span></div>)}</div></section>
            <section className="hf-card p-6"><h2 className="text-lg font-extrabold">Project workflow</h2><ol className="mt-4 space-y-3 text-sm"><li><b>1.</b> Registration & Data - ready</li><li><b>2.</b> Group & Assignment - ready</li><li><b>3.</b> Field Execution - ready</li><li><b>4.</b> Monitoring & Analysis - ready</li><li><b>5.</b> Bal Pravruti - ready</li><li><b>6.</b> Wireframe support modules - ready</li><li><b>7.</b> Production hardening - ready</li></ol></section>
        </div>
    </AppLayout>;
}
function FieldStat({label,value}:{label:string;value:number}) { return <div className="rounded-xl bg-[#faf5fc] p-3 text-center"><div className="text-2xl font-black text-[#5f187c]">{value}</div><div className="text-[11px] font-bold uppercase tracking-wide text-[#76647e]">{label}</div></div>; }
function Leaderboard({title,rows,keyName}:{title:string;rows:any[];keyName:string}){return <section className="hf-card p-5"><div className="flex items-center gap-2 text-[#5c1a73]"><Trophy size={18}/><h2 className="font-extrabold">{title}</h2></div><div className="mt-4 space-y-2">{rows.length===0?<div className="text-sm text-[#75677b]">No active assignment data yet.</div>:rows.map((r:any)=><div key={`${r.rank}-${r[keyName]}`} className="flex items-center gap-3 rounded-xl bg-[#faf6fc] p-3"><span className="flex h-8 w-8 items-center justify-center rounded-full bg-[#eadbf0] font-black text-[#6a1b9a]">{r.rank}</span><div className="min-w-0 flex-1"><div className="truncate font-bold">{r[keyName]}</div><div className="text-xs text-[#796a7d]">{r.completed} main + {r.bal_completed ?? 0} Bal completed / {r.assigned} main assigned</div></div><span className="font-black text-[#5f187c]">{r.completion_percentage}%</span></div>)}</div></section>}
