import { Head, useForm } from '@inertiajs/react';
import AppLayout from '../../layouts/app-layout';
import { ClipboardCheck } from 'lucide-react';

type Center = { id:number; name:string; code:string };
type Correction = {
    id:number; center_id:number|null; module:string; record_reference:string|null; requested_change:string; reason:string;
    status:string; review_note:string|null; reviewed_at:string|null; created_at:string;
    user?:{name:string;email:string}|null; center?:Center|null; reviewer?:{name:string}|null;
};

const modules = ['family','karyakar','group','assignment','area_society','target','home_visit','bal_pravruti','user_access','other'];

export default function Corrections({requests,centers,canManage,canUseGlobal}:{requests:Correction[];centers:Center[];canManage:boolean;canUseGlobal:boolean}){
    const f = useForm({center_id:canUseGlobal?'':String(centers[0]?.id??''),module:'family',record_reference:'',requested_change:'',reason:''});
    return <AppLayout title="Correction / Change Requests"><Head title="Correction Requests"/><div className="grid gap-5 xl:grid-cols-[390px_1fr]">
        <form className="hf-card p-5 space-y-3 h-fit" onSubmit={e=>{e.preventDefault();f.post('/support/corrections',{onSuccess:()=>f.reset('record_reference','requested_change','reason')})}}>
            <div className="flex items-center gap-2"><ClipboardCheck size={18}/><h2 className="font-extrabold">Request a correction</h2></div>
            <p className="text-sm text-[#76647e]">Submit a controlled correction/change request. This request does not automatically alter portal data.</p>
            <Field label="Center"><select className="hf-input" value={f.data.center_id} onChange={e=>f.setData('center_id',e.target.value)}>{canUseGlobal&&<option value="">Organization / Global</option>}{centers.map(c=><option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}</select>{f.errors.center_id&&<ErrorText text={f.errors.center_id}/>}</Field>
            <Field label="Module"><select className="hf-input" value={f.data.module} onChange={e=>f.setData('module',e.target.value)}>{modules.map(x=><option key={x} value={x}>{x.replace('_',' ')}</option>)}</select>{f.errors.module&&<ErrorText text={f.errors.module}/>}</Field>
            <Field label="Record / Reference"><input className="hf-input" placeholder="e.g. HF-GND-000123 or GND-001" value={f.data.record_reference} onChange={e=>f.setData('record_reference',e.target.value)}/>{f.errors.record_reference&&<ErrorText text={f.errors.record_reference}/>}</Field>
            <Field label="Requested Change"><textarea className="hf-input min-h-28" value={f.data.requested_change} onChange={e=>f.setData('requested_change',e.target.value)}/>{f.errors.requested_change&&<ErrorText text={f.errors.requested_change}/>}</Field>
            <Field label="Reason / Change Note"><textarea className="hf-input min-h-24" value={f.data.reason} onChange={e=>f.setData('reason',e.target.value)}/>{f.errors.reason&&<ErrorText text={f.errors.reason}/>}</Field>
            <button className="hf-btn w-full" disabled={f.processing}>Submit Request</button>
        </form>
        <section className="space-y-4">{requests.map(r=><CorrectionCard key={r.id} r={r} canManage={canManage}/>)}{requests.length===0&&<div className="hf-card p-8 text-center text-sm text-[#76647e]">No correction requests found.</div>}</section>
    </div></AppLayout>;
}

function CorrectionCard({r,canManage}:{r:Correction;canManage:boolean}){
    const f = useForm({status:r.status,review_note:r.review_note??''});
    return <article className="hf-card p-5"><div className="flex flex-wrap justify-between gap-3"><div><div className="text-xs font-semibold text-[#78677e]">#{r.id} · {r.module.replace('_',' ')} · {r.center?.code??'Global'} · {r.user?.name??'User'}</div><h2 className="mt-1 font-extrabold">{r.record_reference||'General change request'}</h2></div><span className="hf-badge">{r.status.replace('_',' ')}</span></div><div className="mt-4 grid gap-3 md:grid-cols-2 text-sm"><div className="rounded-xl bg-[#faf7fc] p-3"><b>Requested change</b><p className="mt-1 whitespace-pre-wrap leading-6">{r.requested_change}</p></div><div className="rounded-xl bg-[#faf7fc] p-3"><b>Reason</b><p className="mt-1 whitespace-pre-wrap leading-6">{r.reason}</p></div></div>{r.review_note&&<div className="mt-3 rounded-xl bg-[#f8f1fa] p-3 text-sm"><b>Review note:</b> {r.review_note}{r.reviewer?.name&&<span className="ml-2 text-[#76647e]">- {r.reviewer.name}</span>}</div>}{canManage&&<form className="mt-4 grid gap-2 sm:grid-cols-[170px_1fr_auto]" onSubmit={e=>{e.preventDefault();f.put(`/support/corrections/${r.id}`)}}><select className="hf-input" value={f.data.status} onChange={e=>f.setData('status',e.target.value)}>{['pending','under_review','approved','rejected','completed'].map(x=><option key={x} value={x}>{x.replace('_',' ')}</option>)}</select><div><input className="hf-input" placeholder="Review note" value={f.data.review_note} onChange={e=>f.setData('review_note',e.target.value)}/>{f.errors.review_note&&<ErrorText text={f.errors.review_note}/>}</div><button className="hf-btn" disabled={f.processing}>Update</button></form>}</article>;
}

function Field({label,children}:{label:string;children:any}){return <div><label className="hf-label">{label}</label>{children}</div>}
function ErrorText({text}:{text:string}){return <div className="mt-1 text-xs font-semibold text-red-700">{text}</div>}
