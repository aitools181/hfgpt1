import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, BellRing, Phone } from 'lucide-react';
import AppLayout from '../../layouts/app-layout';

type Event = { id:number; event_type:'reminder'|'alert'; inactivity_days:number; status:string; activity_anchor_at:string; triggered_at:string; resolved_at?:string|null; center:{name:string;code:string}; group:{group_code:string}; karyakar:{full_name:string;karyakar_reference:string;mobile?:string|null}; target?:{name?:string|null;target_quantity:number;completed_quantity:number;status:string}|null };
type Props = { events:{data:Event[];links:{url?:string|null;label:string;active:boolean}[]}; filters:Record<string,string>; isOwnView:boolean };

export default function Reminders({events,isOwnView}:Props) {
    return <AppLayout title="Reminders & Alerts"><Head title="Reminders & Alerts"/>
        <section className="hf-card p-5 mb-5"><div className="flex items-start gap-3"><BellRing className="text-[#6a1b9a]"/><div><h2 className="font-extrabold">4-day Reminder / 7-day Alert history</h2><p className="mt-1 text-sm text-[#76647e]">{isOwnView?'Only your field inactivity records are shown.':'Records are restricted to your authorized organizational scope.'} A new Home Visit resolves open inactivity records for that Group/Karyakar.</p></div></div></section>
        <div className="space-y-3">
            {events.data.map(event => <article key={event.id} className={`hf-card p-4 border-l-4 ${event.event_type==='alert'?'!border-l-red-500':'!border-l-amber-500'}`}>
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2"><span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-extrabold ${event.event_type==='alert'?'bg-red-100 text-red-800':'bg-amber-100 text-amber-900'}`}>{event.event_type==='alert'?<AlertTriangle size={14}/>:<BellRing size={14}/>}<span className="capitalize">{event.event_type}</span></span><span className="hf-badge capitalize">{event.status}</span></div>
                        <div className="mt-2 text-lg font-black">{event.karyakar.full_name} · {event.group.group_code}</div>
                        <div className="text-sm text-[#76647e]">{event.karyakar.karyakar_reference} · {event.center.code} {event.center.name}</div>
                        <div className="mt-2 text-sm"><b>{event.inactivity_days} days</b> since required activity anchor. Triggered {new Date(event.triggered_at).toLocaleString()}.</div>
                        {event.target && <div className="mt-1 text-sm text-[#685570]">Target: {event.target.name || 'Assigned Target'} · {event.target.completed_quantity}/{event.target.target_quantity}</div>}
                    </div>
                    {event.karyakar.mobile && <a href={`tel:${event.karyakar.mobile}`} className="hf-btn hf-btn-secondary inline-flex items-center justify-center gap-1 text-xs"><Phone size={14}/> Call Karyakar</a>}
                </div>
            </article>)}
            {events.data.length===0 && <div className="hf-card p-8 text-center text-sm text-[#76647e]">No reminder or alert records in this scope.</div>}
        </div>
        <div className="mt-5 flex flex-wrap gap-2">{events.links?.map((link,index)=><Link key={index} href={link.url || '#'} className={`rounded-lg px-3 py-2 text-xs font-bold ${link.active?'bg-[#6a1b9a] text-white':'bg-white border border-[#eadff0]'}`} dangerouslySetInnerHTML={{__html:link.label}}/>)}</div>
    </AppLayout>;
}
