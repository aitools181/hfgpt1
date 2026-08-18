import { Form, Head, router, usePage } from '@inertiajs/react';
import { Award, BellRing, CheckCircle2, MapPin, Phone, Target as TargetIcon, UsersRound, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout from '../../layouts/app-layout';
import type { PageProps } from '../../types';

type Karyakar = { id:number; center_id:number; full_name:string; karyakar_reference:string; mobile?:string|null; category:string };
type Family = { id:number; external_family_id?:string|null; manual_reference?:string|null; head_name:string; head_mobile?:string|null; address?:string|null; city_village?:string|null };
type Visit = { id:number; karyakar_id:number; target_id?:number|null; completed_at:string; completion_note?:string|null; is_admin_override:boolean };
type FamilyAssignment = { id:number; slot_number:number; assignment_type:'fixed'|'remaining'; assignment_source:string; family:Family; home_visit?:Visit|null };
type GroupKaryakar = { karyakar:{ id:number; full_name:string; mobile?:string|null; karyakar_reference:string } };
type Group = { id:number; group_code:string; group_type:string; status:string; center:{name:string; code:string; zone?:{name:string}|null}; area?:{name:string}|null; society?:{name:string}|null; karyakar_assignments:GroupKaryakar[]; family_assignments:FamilyAssignment[] };
type TargetRow = { id:number; group_id:number; karyakar_id?:number|null; name?:string|null; start_date:string; end_date:string; target_quantity:number; completed_quantity:number; remaining_quantity:number; completion_percentage:number; status:string; group:{group_code:string}; area:{name:string}; society?:{name:string}|null };
type BadgeSummary = { completedFamilies:number; currentMilestone?:number|null; nextMilestone?:number|null; remainingToNext:number; earned:{milestone:number; badge_key:string; awarded_at:string}[] };
type EventRow = { id:number; event_type:'reminder'|'alert'; inactivity_days:number; status:string; triggered_at:string; group:{group_code:string} };
type AdminChoice = { id:number; center_id:number; full_name:string; karyakar_reference:string };
type CompletionReport = { zone?:string|null; center?:string|null; group:string; karyakar:string; completedFamilies:number; messagesDelivered:number; groupCompleted:number; groupPending:number; ownGroupCompleted:number; targetName:string; targetQuantity:number; targetCompleted:number; targetPending:number; completionRatio:number; analysis:string };

type Props = {
    karyakar: Karyakar | null;
    groups: Group[];
    targets: TargetRow[];
    badgeSummary: BadgeSummary;
    openEvents: EventRow[];
    adminChoices: AdminChoice[];
    isAdminPreview: boolean;
    isSuperAdmin: boolean;
};

const pct = (value:number) => `${Math.max(0, Math.min(100, value))}%`;

export default function MyTarget({ karyakar, groups, targets, badgeSummary, openEvents, adminChoices, isAdminPreview, isSuperAdmin }: Props) {
    const page = usePage<PageProps>();
    const flashReport = page.props.flash.completionReport as CompletionReport | null | undefined;
    const [report, setReport] = useState<CompletionReport | null>(flashReport ?? null);
    const targetByGroup = useMemo(() => {
        const map = new Map<number, TargetRow>();
        for (const target of targets) {
            const previous = map.get(target.group_id);
            if (!previous || (karyakar && target.karyakar_id === karyakar.id && previous.karyakar_id !== karyakar.id)) map.set(target.group_id, target);
        }
        return map;
    }, [targets, karyakar?.id]);

    const totalAssigned = groups.reduce((sum, group) => sum + group.family_assignments.length, 0);
    const totalCompleted = groups.reduce((sum, group) => sum + group.family_assignments.filter(item => item.home_visit).length, 0);
    const overall = totalAssigned > 0 ? Math.round((totalCompleted / totalAssigned) * 100) : 0;

    if (!karyakar) {
        return <AppLayout title="My Target / Home Visit"><Head title="My Target" />
            <section className="hf-card p-5 mb-5">
                <div className="font-extrabold">Super Admin field preview</div>
                <p className="mt-1 text-sm text-[#76647e]">Select an approved Sankalp Karyakar to preview My Target, Home Visit progress, badges and reminders.</p>
                {adminChoices.length > 0 ? <select className="hf-input mt-4 max-w-xl" defaultValue="" onChange={e => { if (e.target.value) router.get('/field/my-target', { karyakar_id: e.target.value }, { preserveState:false }); }}>
                    <option value="" disabled>Select approved Karyakar</option>
                    {adminChoices.map(choice => <option key={choice.id} value={choice.id}>{choice.karyakar_reference} - {choice.full_name}</option>)}
                </select> : <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">No approved Sankalp Karyakar is available yet. Super Admin access is working; approve or link a Karyakar before using the field preview.</div>}
            </section>
            <section className="hf-card p-10 text-center"><UsersRound className="mx-auto text-[#8d6b99]"/><h2 className="mt-3 text-lg font-extrabold">No Karyakar selected</h2><p className="mt-1 text-sm text-[#76647e]">This page no longer returns 403 for Super Admin when no approved Karyakar is linked or available.</p></section>
        </AppLayout>;
    }

    return <AppLayout title="My Target / Home Visit"><Head title="My Target" />
        {isSuperAdmin && adminChoices.length > 0 && <section className="hf-card p-4 mb-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div><div className="font-extrabold">Super Admin field preview</div><div className="text-sm text-[#76647e]">Preview an approved Karyakar's mobile field view. Override completion still requires a reason.</div></div>
                <select className="hf-input sm:max-w-sm" value={karyakar.id} onChange={e => router.get('/field/my-target', { karyakar_id: e.target.value }, { preserveState:false })}>
                    {adminChoices.map(choice => <option key={choice.id} value={choice.id}>{choice.karyakar_reference} - {choice.full_name}</option>)}
                </select>
            </div>
        </section>}

        {openEvents.length > 0 && <section className="mb-4 space-y-2">
            {openEvents.map(event => <div key={event.id} className={`rounded-xl border p-3 text-sm font-semibold ${event.event_type==='alert'?'border-red-200 bg-red-50 text-red-800':'border-amber-200 bg-amber-50 text-amber-900'}`}>
                <div className="flex items-center gap-2"><BellRing size={17}/><span className="capitalize">{event.event_type}</span> - {event.group.group_code}: {event.inactivity_days} days without required activity.</div>
            </div>)}
        </section>}

        <section className="hf-card overflow-hidden mb-5">
            <div className="hf-brand p-5 text-white">
                <div className="text-xs uppercase tracking-[.16em] opacity-80">Sankalp Karyakar</div>
                <div className="mt-1 text-2xl font-black">{karyakar.full_name}</div>
                <div className="mt-1 text-sm opacity-90">{karyakar.karyakar_reference} · {karyakar.category}</div>
                {isAdminPreview && <div className="mt-2 inline-flex rounded-full bg-white/15 px-3 py-1 text-xs font-bold">Admin preview</div>}
            </div>
            <div className="grid grid-cols-2 gap-3 p-4 sm:grid-cols-4">
                <Stat label="Groups" value={groups.length}/><Stat label="Assigned Families" value={totalAssigned}/><Stat label="Completed" value={totalCompleted}/><Stat label="Overall" value={`${overall}%`}/>
            </div>
            <div className="px-4 pb-4"><Progress value={overall}/></div>
        </section>

        <div className="grid gap-5 xl:grid-cols-[1fr_320px]">
            <div className="space-y-5">
                {groups.map(group => {
                    const target = targetByGroup.get(group.id);
                    const completed = group.family_assignments.filter(item => item.home_visit).length;
                    const pending = group.family_assignments.length - completed;
                    const groupPct = group.family_assignments.length ? Math.round(completed / group.family_assignments.length * 100) : 0;
                    return <section key={group.id} className="hf-card overflow-hidden">
                        <div className="border-b border-[#eadff0] p-4 md:p-5">
                            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2"><h2 className="text-xl font-black text-[#4d1765]">{group.group_code}</h2><span className="hf-badge capitalize">{group.group_type.replaceAll('_',' ')}</span></div>
                                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-[#6e5b76]"><span className="inline-flex items-center gap-1"><MapPin size={15}/>{group.area?.name ?? 'Area not assigned'}{group.society ? ` / ${group.society.name}` : ''}</span><span>{group.center.name}{group.center.zone?.name ? ` · ${group.center.zone.name}` : ''}</span></div>
                                </div>
                                <div className="min-w-[160px] text-sm"><div className="flex justify-between"><span>Completed</span><b>{completed}/10</b></div><Progress value={groupPct}/></div>
                            </div>
                            <div className="mt-4 grid gap-2 sm:grid-cols-2">
                                {group.karyakar_assignments.map(item => <div key={item.karyakar.id} className="rounded-xl bg-[#faf5fc] p-3 text-sm"><div className="font-bold">{item.karyakar.full_name}</div><div className="text-xs text-[#76647e]">{item.karyakar.karyakar_reference}</div>{item.karyakar.mobile && <a className="mt-2 inline-flex items-center gap-1 font-bold text-[#6a1b9a]" href={`tel:${item.karyakar.mobile}`}><Phone size={14}/> Call Karyakar</a>}</div>)}
                            </div>
                        </div>

                        {target && <div className="border-b border-[#eadff0] bg-[#fbf8fd] p-4 md:p-5">
                            <div className="flex flex-wrap items-center justify-between gap-2"><div className="font-extrabold inline-flex items-center gap-2"><TargetIcon size={18}/> {target.name || 'Assigned Target'}</div><span className="hf-badge capitalize">{target.status}</span></div>
                            <div className="mt-3 grid grid-cols-3 gap-2 text-center"><Mini label="Target" value={target.target_quantity}/><Mini label="Completed" value={target.completed_quantity}/><Mini label="Remaining" value={target.remaining_quantity}/></div>
                            <div className="mt-3"><div className="mb-1 flex justify-between text-xs font-semibold"><span>{target.start_date} - {target.end_date}</span><span>{target.completion_percentage}%</span></div><Progress value={target.completion_percentage}/></div>
                        </div>}

                        <div className="p-4 md:p-5">
                            <div className="mb-3 flex items-center justify-between"><h3 className="font-extrabold">Completion Checklist</h3><span className="text-xs font-semibold text-[#76647e]">{pending} pending</span></div>
                            <div className="space-y-3">
                                {group.family_assignments.map(item => <FamilyChecklistItem key={item.id} item={item} group={group} target={target} karyakar={karyakar} isAdminPreview={isAdminPreview}/>) }
                            </div>
                        </div>
                    </section>;
                })}
                {groups.length === 0 && <section className="hf-card p-8 text-center"><UsersRound className="mx-auto text-[#8d6b99]"/><h2 className="mt-3 font-extrabold">No active Group assignment</h2><p className="mt-1 text-sm text-[#76647e]">An active Group with assigned Sankalp Families is required before Home Visit completion can begin.</p></section>}
            </div>

            <aside className="space-y-5">
                <section className="hf-card p-5">
                    <div className="flex items-center gap-2"><Award size={20} className="text-[#6a1b9a]"/><h2 className="font-extrabold">Motivation Badge</h2></div>
                    <div className="mt-4 text-center"><div className="text-4xl font-black text-[#6a1b9a]">{badgeSummary.currentMilestone ?? 0}</div><div className="text-xs font-bold uppercase tracking-wide text-[#76647e]">Families milestone</div></div>
                    <div className="mt-4 flex justify-between gap-1">{[3,6,9,12,15].map(m => <div key={m} className={`flex h-10 w-10 items-center justify-center rounded-full text-xs font-black ${badgeSummary.completedFamilies>=m?'bg-[#6a1b9a] text-white':'bg-[#f1e8f5] text-[#82678d]'}`}>{m}</div>)}</div>
                    {badgeSummary.nextMilestone ? <p className="mt-4 text-sm text-[#685570]">Complete <b>{badgeSummary.remainingToNext}</b> more {badgeSummary.remainingToNext===1?'family':'families'} to reach the {badgeSummary.nextMilestone}-family badge.</p> : <p className="mt-4 text-sm font-bold text-[#5d1b78]">All defined 3/6/9/12/15 milestones achieved.</p>}
                </section>
                <section className="hf-card p-5"><h2 className="font-extrabold">Field rules</h2><ul className="mt-3 space-y-2 text-sm text-[#685570]"><li>Open only Families assigned to your active Group.</li><li>Use click-to-call only when a mobile number is available.</li><li>Mark completion after the Happy Family message is delivered.</li><li>No GPS or mandatory photo is required by the baseline SRS.</li></ul></section>
            </aside>
        </div>

        {report && <div className="hf-modal-layer fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4" role="dialog" aria-modal="true">
            <div className="hf-modal-sheet w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div className="hf-brand flex items-start justify-between gap-4 p-5 text-white"><div><div className="text-xs uppercase tracking-[.16em] opacity-80">Completion Report</div><h2 className="mt-1 text-2xl font-black">Happy Family message completed</h2></div><button className="rounded-full bg-white/15 p-2" onClick={() => setReport(null)} aria-label="Close"><X size={18}/></button></div>
                <div className="p-5">
                    <div className="grid grid-cols-2 gap-3"><Mini label="Zone" value={report.zone || '-'}/><Mini label="Center" value={report.center || '-'}/><Mini label="My completed" value={report.completedFamilies}/><Mini label="Group pending" value={report.groupPending}/></div>
                    <div className="mt-4 rounded-xl bg-[#faf5fc] p-4"><div className="flex justify-between gap-3 text-sm"><span>{report.targetName}</span><b>{report.targetCompleted}/{report.targetQuantity}</b></div><div className="mt-2"><Progress value={report.completionRatio}/></div><div className="mt-2 flex justify-between text-xs font-semibold"><span>{report.analysis}</span><span>{report.completionRatio}%</span></div></div>
                    <div className="mt-4 text-sm text-[#685570]">Messages delivered by {report.karyakar}: <b>{report.messagesDelivered}</b>. Current Group {report.group}: <b>{report.groupCompleted}</b> completed and <b>{report.groupPending}</b> pending.</div>
                    <button className="hf-btn mt-5 w-full" onClick={() => setReport(null)}>Continue</button>
                </div>
            </div>
        </div>}
    </AppLayout>;
}

function FamilyChecklistItem({ item, group, target, karyakar, isAdminPreview }:{ item:FamilyAssignment; group:Group; target?:TargetRow; karyakar:Karyakar; isAdminPreview:boolean }) {
    const [showForm, setShowForm] = useState(false);
    const done = Boolean(item.home_visit);
    const ref = item.family.external_family_id ?? item.family.manual_reference ?? `Family #${item.family.id}`;
    return <div className={`rounded-xl border p-3 ${done?'border-green-200 bg-green-50/60':'border-[#eadff0] bg-white'}`}>
        <div className="flex items-start gap-3">
            <div className={`mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${done?'bg-green-600 text-white':'bg-[#f1e8f5] text-[#6a1b9a]'}`}>{done?<CheckCircle2 size={17}/>:item.slot_number}</div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2"><div className="font-extrabold">{item.family.head_name}</div><span className="hf-badge">{item.assignment_type==='fixed'?'Fixed / Locked':'Remaining'}</span>{done && <span className="rounded-full bg-green-100 px-2 py-1 text-[11px] font-bold text-green-800">Completed</span>}</div>
                <div className="mt-1 text-xs text-[#76647e]">{ref}{item.family.city_village ? ` · ${item.family.city_village}` : ''}</div>
                {item.family.address && <div className="mt-1 text-sm text-[#685570]">{item.family.address}</div>}
                <div className="mt-3 flex flex-wrap gap-2">
                    {item.family.head_mobile && <a href={`tel:${item.family.head_mobile}`} className="hf-btn hf-btn-secondary !px-3 !py-2 text-xs inline-flex items-center gap-1"><Phone size={14}/> Call Head of Family</a>}
                    {!done && <button type="button" onClick={() => setShowForm(v=>!v)} className="hf-btn !px-3 !py-2 text-xs">Complete Home Visit</button>}
                </div>
                {done && <div className="mt-2 text-xs text-green-800">Completed {new Date(item.home_visit!.completed_at).toLocaleString()}{item.home_visit!.is_admin_override?' · Admin override':''}</div>}
                {showForm && !done && <Form action={`/field/home-visits/${item.id}`} method="post" className="mt-3 space-y-3 rounded-xl bg-[#faf7fc] p-3">{({errors,processing}) => <>
                    {target && <input type="hidden" name="target_id" value={target.id}/>} 
                    {isAdminPreview && <><input type="hidden" name="karyakar_id" value={karyakar.id}/><label className="block"><span className="hf-label">Override reason</span><input name="override_reason" className="hf-input" required placeholder="Reason for Super Admin completion override"/>{errors.override_reason && <span className="hf-error">{errors.override_reason}</span>}</label></>}
                    <label className="block"><span className="hf-label">Completion note (optional)</span><textarea name="completion_note" className="hf-input" rows={2} placeholder="Relevant visit note"/></label>
                    {errors.family && <div className="hf-error">{errors.family}</div>}{errors.authorization && <div className="hf-error">{errors.authorization}</div>}
                    <div className="flex gap-2"><button className="hf-btn flex-1" disabled={processing}>{processing?'Saving...':'Confirm Message Delivered'}</button><button type="button" className="hf-btn hf-btn-secondary" onClick={()=>setShowForm(false)}>Cancel</button></div>
                </>}</Form>}
            </div>
        </div>
    </div>;
}

function Stat({label,value}:{label:string;value:string|number}) { return <div className="rounded-xl bg-[#faf5fc] p-3 text-center"><div className="text-2xl font-black text-[#5f187c]">{value}</div><div className="text-[11px] font-bold uppercase tracking-wide text-[#76647e]">{label}</div></div>; }
function Mini({label,value}:{label:string;value:string|number}) { return <div className="rounded-xl bg-[#faf5fc] p-3"><div className="text-lg font-black text-[#5f187c]">{value}</div><div className="text-[11px] font-bold uppercase tracking-wide text-[#76647e]">{label}</div></div>; }
function Progress({value}:{value:number}) { return <div className="h-2.5 overflow-hidden rounded-full bg-[#eadff0]"><div className="h-full rounded-full bg-[#6a1b9a] transition-all" style={{width:pct(value)}}/></div>; }
