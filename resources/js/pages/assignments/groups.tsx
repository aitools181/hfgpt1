import { Form, Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppLayout from '../../layouts/app-layout';
import FormField from '../../components/form-field';

type Center = { id:number; name:string; code:string };
type Karyakar = { id:number; center_id:number; full_name:string; gender:string; category:string; karyakar_reference:string };
type GroupRow = { id:number; group_code:string; group_type:string; status:string; center:Center; area?:{id:number;name:string}|null; society?:{id:number;name:string}|null; active_karyakars_count:number; active_families_count:number; fixed_families_count:number; remaining_families_count:number };
type Pagination = { data:GroupRow[]; total:number; current_page:number; last_page:number; prev_page_url:string|null; next_page_url:string|null };
type Props = { groups:Pagination; centers:Center[]; karyakars:Karyakar[]; groupTypes:string[]; canCreate:boolean; filters:Record<string,string|undefined> };

const typeLabel = (value:string) => ({couple:'Husband + Wife / Couple', two_male:'2 Male Karyakars', two_female:'2 Female Karyakars'}[value] ?? value);

export default function Groups({groups,centers,karyakars,groupTypes,canCreate,filters}:Props) {
    const [centerId,setCenterId] = useState(String(centers[0]?.id ?? ''));
    const [selected,setSelected] = useState<string[]>([]);
    const candidates = useMemo(()=>karyakars.filter(k=>String(k.center_id)===centerId),[karyakars,centerId]);
    const filter=(key:string,value:string)=>router.get('/assignments/groups',{...filters,[key]:value},{preserveState:true,replace:true});
    const toggle=(id:number)=>setSelected(current=>current.includes(String(id))?current.filter(x=>x!==String(id)):(current.length<2?[...current,String(id)]:current));

    return <AppLayout title="Group Management"><Head title="Group Management" />
        <div className="space-y-5">
            <section className="hf-card p-5 overflow-x-auto">
                <div className="flex flex-wrap items-start justify-between gap-3 mb-4"><div><h2 className="text-lg font-extrabold">Sankalp Groups</h2><p className="text-sm text-[#76647e]">Every Group is created with exactly 2 approved Karyakars. One Karyakar may appear in multiple active Groups.</p></div><span className="hf-badge">{groups.total} groups</span></div>
                <div className="grid gap-2 md:grid-cols-4 mb-4">
                    <input className="hf-input" placeholder="Search Group code" defaultValue={filters.search??''} onKeyDown={e=>{if(e.key==='Enter')filter('search',(e.target as HTMLInputElement).value)}}/>
                    <select className="hf-input" value={filters.center_id??''} onChange={e=>filter('center_id',e.target.value)}><option value="">All centers</option>{centers.map(c=><option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}</select>
                    <select className="hf-input" value={filters.group_type??''} onChange={e=>filter('group_type',e.target.value)}><option value="">All group types</option>{groupTypes.map(t=><option key={t} value={t}>{typeLabel(t)}</option>)}</select>
                    <select className="hf-input" value={filters.status??''} onChange={e=>filter('status',e.target.value)}><option value="">All status</option><option value="draft">Draft</option><option value="active">Active</option><option value="closed">Closed</option></select>
                </div>
                <table className="hf-table min-w-[950px]"><thead><tr><th>Group</th><th>Center</th><th>Type</th><th>Karyakars</th><th>Families</th><th>Area / Society</th><th>Status</th><th></th></tr></thead><tbody>{groups.data.map(g=><tr key={g.id}><td className="font-extrabold text-[#651c82]">{g.group_code}</td><td>{g.center.code}</td><td>{typeLabel(g.group_type)}</td><td>{g.active_karyakars_count}/2</td><td><b>{g.active_families_count}/10</b><div className="text-xs text-[#76647e]">Fixed {g.fixed_families_count} · Remaining {g.remaining_families_count}</div></td><td>{g.area?.name??'Unassigned'}{g.society?.name?<div className="text-xs">{g.society.name}</div>:null}</td><td><span className="hf-badge capitalize">{g.status}</span></td><td><Link className="text-sm font-bold text-[#6a1b9a]" href={`/assignments/groups/${g.id}`}>Open</Link></td></tr>)}</tbody></table>
                <div className="flex justify-between mt-4 text-sm"><span>Page {groups.current_page} / {groups.last_page}</span><div className="flex gap-2">{groups.prev_page_url&&<Link className="hf-btn hf-btn-secondary" href={groups.prev_page_url}>Previous</Link>}{groups.next_page_url&&<Link className="hf-btn hf-btn-secondary" href={groups.next_page_url}>Next</Link>}</div></div>
            </section>

            {canCreate&&<section className="hf-card p-5"><h2 className="text-lg font-extrabold">Create Group - exactly 2 Karyakars</h2><p className="text-sm text-[#76647e] mb-4">Group code is generated automatically from Center Code, for example GND-001.</p>
                <Form action="/assignments/groups" method="post" className="grid gap-4 lg:grid-cols-[.7fr_.8fr_1.5fr]">{({errors,processing})=><>
                    <FormField label="Center" error={errors.center_id}><select name="center_id" className="hf-input" value={centerId} onChange={e=>{setCenterId(e.target.value);setSelected([])}} required>{centers.map(c=><option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}</select></FormField>
                    <FormField label="Group Type" error={errors.group_type}><select name="group_type" className="hf-input" required>{groupTypes.map(t=><option key={t} value={t}>{typeLabel(t)}</option>)}</select></FormField>
                    <div><div className="hf-label">Approved Karyakars - select exactly 2</div><div className="max-h-56 overflow-auto rounded-xl border border-[#eadff0] p-2 space-y-1">{candidates.length===0?<div className="text-sm text-[#76647e] p-2">No approved Karyakars for this Center.</div>:candidates.map(k=><label key={k.id} className="flex items-center gap-2 rounded-lg p-2 hover:bg-[#faf5fc]"><input type="checkbox" checked={selected.includes(String(k.id))} onChange={()=>toggle(k.id)}/><span className="text-sm"><b>{k.full_name}</b> <span className="capitalize">({k.gender})</span><span className="block text-xs text-[#76647e]">{k.karyakar_reference} · {k.category}</span></span></label>)}</div>{selected.map(id=><input key={id} type="hidden" name="karyakar_ids[]" value={id}/>)}{errors.karyakar_ids&&<div className="hf-error">{errors.karyakar_ids}</div>}</div>
                    <div className="lg:col-span-3 flex justify-end"><button className="hf-btn" disabled={processing||selected.length!==2}>Create Group with 2 Karyakars</button></div>
                </>}</Form>
            </section>}
        </div>
    </AppLayout>;
}
