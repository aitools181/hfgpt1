import { Head, Link } from '@inertiajs/react';
import { BarChart3, Download, Filter, Trophy } from 'lucide-react';
import AppLayout from '../../layouts/app-layout';

type Option = { id:number; name?:string; code?:string; group_code?:string; full_name?:string; gender?:string; category?:string; center_id?:number; status?:string; member_names?:string[] };
type Filters = { center_id:number|null; group_id:number|null; karyakar_id:number|null; area_id:number|null; gender:string|null; category:string|null; status:string|null; group_status:string|null; date_from:string|null; date_to:string|null; female_scope_locked:boolean };
type Row = Record<string, any>;
type Props = {
    analysis: {
        filters: Filters;
        summary: Record<string, number>;
        groupRows: Row[];
        centerPerformance: Row[];
        zonePerformance: Row[];
        genderDistribution: {label:string; key:string; value:number}[];
        categoryDistribution: {label:string; value:number}[];
        completionTrend: {date:string; completed:number}[];
        centerLeaderboard: Row[];
        zoneLeaderboard: Row[];
    };
    options: { centers:Option[]; groups:Option[]; karyakars:Option[]; areas:Option[]; categories:string[] };
};

export default function Analysis({ analysis, options }: Props) {
    const s = analysis.summary;
    const maxTrend = Math.max(1, ...analysis.completionTrend.map((row) => row.completed));
    const maxCategory = Math.max(1, ...analysis.categoryDistribution.map((row) => row.value));
    return <AppLayout title="Reports & Analysis"><Head title="Monitoring & Analysis" />
        <section className="hf-card mb-5 p-5">
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div><div className="text-xs font-bold uppercase tracking-[.15em] text-[#7b5f87]">Monitoring + Bal contribution</div><h2 className="text-xl font-black text-[#351342]">Role-scoped campaign analysis</h2><p className="mt-1 text-sm text-[#6f6075]">All totals and drill-downs are limited to the signed-in user's permitted organization scope.</p></div>
                <Link href="/monitoring/reports" className="hf-btn hf-btn-secondary inline-flex items-center gap-2"><Download size={16}/> Open Reports</Link>
            </div>
            <FilterForm filters={analysis.filters} options={options}/>
        </section>

        {analysis.filters.female_scope_locked && <div className="mb-5 rounded-xl border border-fuchsia-200 bg-fuchsia-50 p-4 text-sm font-semibold text-fuchsia-900">BN Karyalay analysis is locked to the Female scope as required. Operational administration remains organization-wide according to the assigned role.</div>}

        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Metric label="Centers" value={s.centers}/><Metric label="Approved Karyakars" value={s.approvedKaryakars}/><Metric label="Assigned Families" value={s.assignedFamilies}/><Metric label="Main Completed" value={s.completedFamilies}/>
            <Metric label="Bal Completed" value={s.balCompletedFamilies}/><Metric label="Overall Completed" value={s.overallCompletedFamilies}/><Metric label="Pending Main Families" value={s.pendingFamilies}/><Metric label="Main Completion" value={`${s.completionPercentage}%`}/>
        </div>

        <section className="hf-card mt-5 p-5 overflow-x-auto"><div className="mb-3 flex flex-wrap items-start justify-between gap-2"><SectionTitle icon={<BarChart3 size={18}/>} title="Filtered Groups & Members"/><span className="hf-badge">{analysis.groupRows.length} groups</span></div><p className="mb-3 text-xs text-[#76647e]">Use Group Status, Group, Karyakar and other filters above. Active / Draft / Closed groups are listed with their two current Karyakars.</p><div className="hf-table-scroll"><table className="hf-table hf-mobile-table min-w-[900px]"><thead><tr><th>Group</th><th>Status</th><th>Center</th><th>Group Members</th><th>Families</th><th>Area / Society</th></tr></thead><tbody>{analysis.groupRows.length===0?<tr><td colSpan={6}>No groups for the selected filters.</td></tr>:analysis.groupRows.map((g:any)=><tr key={g.id}><td><b>{g.group_code}</b><div className="text-xs text-[#76647e]">{g.group_type}</div></td><td><span className="hf-badge capitalize">{g.status}</span></td><td>{g.center_code} - {g.center}</td><td>{(g.members??[]).map((m:any)=><div key={m.id}><b>{m.name}</b><span className="ml-1 text-xs text-[#76647e]">{m.reference}</span></div>)}</td><td>{g.active_families_count}/10</td><td>{g.area??'Unassigned'}{g.society?<div className="text-xs">{g.society}</div>:null}</td></tr>)}</tbody></table></div></section>

        <div className="mt-5 grid gap-5 xl:grid-cols-2">
            <section className="hf-card p-5"><SectionTitle icon={<BarChart3 size={18}/>} title="Zone Main + Bal Completion"/><div className="mt-4 space-y-4">{analysis.zonePerformance.length === 0 ? <Empty/> : analysis.zonePerformance.map((row) => <ProgressRow key={String(row.zone_id)} label={row.zone} sub={`${row.completed} main + ${row.bal_completed ?? 0} Bal completed / ${row.assigned} main assigned`} percent={row.completion_percentage}/>)}</div></section>
            <section className="hf-card p-5"><SectionTitle icon={<BarChart3 size={18}/>} title="Center Performance"/><div className="mt-4 space-y-4">{analysis.centerPerformance.length === 0 ? <Empty/> : analysis.centerPerformance.map((row) => <ProgressRow key={String(row.center_id)} label={`${row.center} (${row.center_code})`} sub={`${row.completed} main + ${row.bal_completed ?? 0} Bal completed · ${row.pending} main pending`} percent={row.completion_percentage}/>)}</div></section>
        </div>

        <div className="mt-5 grid gap-5 xl:grid-cols-[1.1fr_.9fr]">
            <section className="hf-card p-5"><SectionTitle icon={<BarChart3 size={18}/>} title="Overall Completion Trend (Main + Bal)"/><div className="mt-5 flex h-48 items-end gap-1 overflow-x-auto border-b border-[#eadff0] pb-1">{analysis.completionTrend.map((row) => <div key={row.date} className="group flex min-w-[22px] flex-1 flex-col items-center justify-end gap-1" title={`${row.date}: ${row.completed}`}><div className="w-full rounded-t bg-[#7b2d96]" style={{height:`${Math.max(3,(row.completed/maxTrend)*150)}px`}}/><span className="hidden text-[9px] text-[#7b6b80] 2xl:block">{row.date.slice(5)}</span></div>)}</div></section>
            <section className="hf-card p-5"><SectionTitle icon={<BarChart3 size={18}/>} title="Karyakar Category Distribution"/><div className="mt-4 space-y-3">{analysis.categoryDistribution.length === 0 ? <Empty/> : analysis.categoryDistribution.map((row) => <div key={row.label}><div className="flex justify-between gap-3 text-sm"><span className="font-semibold">{row.label}</span><span>{row.value}</span></div><div className="mt-1 h-2 rounded-full bg-[#efe8f2]"><div className="h-2 rounded-full bg-[#6a1b9a]" style={{width:`${Math.max(2,(row.value/maxCategory)*100)}%`}}/></div></div>)}</div></section>
        </div>

        <div className="mt-5 grid gap-5 xl:grid-cols-2">
            <Leaderboard title="Zone-wise Leaderboard" rows={analysis.zoneLeaderboard} labelKey="zone"/>
            <Leaderboard title="Center-wise Leaderboard" rows={analysis.centerLeaderboard} labelKey="center"/>
        </div>

        <section className="hf-card mt-5 p-5 overflow-x-auto"><SectionTitle icon={<BarChart3 size={18}/>} title="Center Drill-down"/><div className="hf-table-scroll"><table className="hf-table hf-mobile-table mt-3"><thead><tr><th>Center</th><th>Karyakars</th><th>Groups</th><th>Main Assigned</th><th>Main Completed</th><th>Bal Completed</th><th>Overall Completed</th><th>Main Pending</th><th>Main %</th></tr></thead><tbody>{analysis.centerPerformance.map((r)=><tr key={r.center_id}><td className="font-semibold">{r.center}</td><td>{r.karyakars}</td><td>{r.groups}</td><td>{r.assigned}</td><td>{r.completed}</td><td>{r.bal_completed ?? 0}</td><td>{r.overall_completed ?? r.completed}</td><td>{r.pending}</td><td>{r.completion_percentage}%</td></tr>)}</tbody></table></div></section>
    </AppLayout>;
}

function FilterForm({filters, options}:{filters:Filters; options:Props['options']}) {
    return <form method="get" action="/monitoring/analysis" className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
        <Select name="center_id" label="Center" value={filters.center_id ?? ''}><option value="">All permitted centers</option>{options.centers.map(o=><option key={o.id} value={o.id}>{o.name} ({o.code})</option>)}</Select>
        <Select name="group_id" label="Group" value={filters.group_id ?? ''}><option value="">All groups</option>{options.groups.map(o=><option key={o.id} value={o.id}>{o.group_code}{o.member_names?.length?` — ${o.member_names.join(' + ')}`:''}</option>)}</Select>
        <Select name="group_status" label="Group Status" value={filters.group_status ?? ''}><option value="">All group status</option><option value="active">Active</option><option value="non_active">Non-active (Draft / Closed)</option></Select>
        <Select name="karyakar_id" label="Karyakar" value={filters.karyakar_id ?? ''}><option value="">All Karyakars</option>{options.karyakars.map(o=><option key={o.id} value={o.id}>{o.full_name}</option>)}</Select>
        <Select name="area_id" label="Area" value={filters.area_id ?? ''}><option value="">All areas</option>{options.areas.map(o=><option key={o.id} value={o.id}>{o.name}</option>)}</Select>
        <Select name="gender" label="Gender" value={filters.gender ?? ''} disabled={filters.female_scope_locked}><option value="">All</option><option value="male">Male</option><option value="female">Female</option></Select>
        {filters.female_scope_locked && <input type="hidden" name="gender" value="female"/>}
        <Select name="category" label="Category" value={filters.category ?? ''}><option value="">All categories</option>{options.categories.map(c=><option key={c} value={c}>{c}</option>)}</Select>
        <div><label className="hf-label">From</label><input className="hf-input" type="date" name="date_from" defaultValue={filters.date_from ?? ''}/></div>
        <div><label className="hf-label">To</label><input className="hf-input" type="date" name="date_to" defaultValue={filters.date_to ?? ''}/></div>
        <div className="flex items-end gap-2"><button className="hf-btn inline-flex items-center gap-2" type="submit"><Filter size={16}/> Apply</button><Link className="hf-btn hf-btn-secondary" href="/monitoring/analysis">Reset</Link></div>
    </form>;
}
function Select({label,children,value,...props}:any){return <div><label className="hf-label">{label}</label><select key={`${props.name??label}-${String(value)}`} className="hf-input" defaultValue={value} {...props}>{children}</select></div>}
function Metric({label,value}:{label:string;value:string|number}){return <div className="hf-card p-5"><div className="text-sm font-semibold text-[#7a657f]">{label}</div><div className="mt-2 text-3xl font-black text-[#5f187c]">{value}</div></div>}
function SectionTitle({icon,title}:{icon:any;title:string}){return <div className="flex items-center gap-2 text-[#4f1964]">{icon}<h2 className="font-extrabold">{title}</h2></div>}
function ProgressRow({label,sub,percent}:{label:string;sub:string;percent:number}){return <div><div className="flex items-end justify-between gap-3"><div><div className="font-bold">{label}</div><div className="text-xs text-[#7b6b80]">{sub}</div></div><div className="font-black text-[#611a7a]">{percent}%</div></div><div className="mt-2 h-2.5 rounded-full bg-[#eee5f1]"><div className="h-2.5 rounded-full bg-[#6a1b9a]" style={{width:`${Math.min(100,Math.max(0,percent))}%`}}/></div></div>}
function Leaderboard({title,rows,labelKey}:{title:string;rows:Row[];labelKey:string}){return <section className="hf-card p-5"><SectionTitle icon={<Trophy size={18}/>} title={title}/><div className="mt-4 space-y-2">{rows.length===0?<Empty/>:rows.slice(0,10).map(r=><div key={`${r.rank}-${r[labelKey]}`} className="flex items-center gap-3 rounded-xl bg-[#faf6fc] p-3"><span className="flex h-8 w-8 items-center justify-center rounded-full bg-[#eadbf0] font-black text-[#6a1b9a]">{r.rank}</span><div className="min-w-0 flex-1"><div className="truncate font-bold">{r[labelKey]}</div><div className="text-xs text-[#796a7d]">{r.completed} main + {r.bal_completed ?? 0} Bal completed</div></div><span className="font-black text-[#5f187c]">{r.completion_percentage}%</span></div>)}</div></section>}
function Empty(){return <div className="rounded-xl bg-[#faf7fb] p-4 text-sm text-[#74677a]">No data for the selected filters.</div>}
