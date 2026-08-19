import { Form, Link, router } from '@inertiajs/react';
import AppLayout from '../../layouts/app-layout';
import FormField from '../../components/form-field';
import { useMemo, useState } from 'react';
import { Plus, Search, Trash2 } from 'lucide-react';

type Center = { id:number; name:string; code:string };
type Area = { id:number; center_id:number; name:string };
type Society = { id:number; center_id:number; sampark_area_id:number|null; name:string };
type Family = { id:number; external_family_id:string|null; manual_reference:string|null; source:string; head_name:string; head_mobile:string|null; city_village:string|null; status:string; members_count:number; male_count:number; female_count:number; center:Center; area?:{name:string}|null; society?:{name:string}|null; group_assignments?:{id:number;assignment_type:string;group:{id:number;group_code:string}}[] };
type Paginated = { data:Family[]; current_page:number; last_page:number; total:number; prev_page_url:string|null; next_page_url:string|null };

type MemberDraft = { name:string; gender:'male'|'female'; age:string; mobile:string; relationship:string; is_head:boolean };
const newMember = (): MemberDraft => ({ name:'', gender:'male', age:'', mobile:'', relationship:'', is_head:false });

export default function Families({ families, centers, areas, societies, filters }: { families:Paginated; centers:Center[]; areas:Area[]; societies:Society[]; filters:Record<string,string> }) {
    const [members, setMembers] = useState<MemberDraft[]>([newMember()]);
    const [centerId, setCenterId] = useState(filters.center_id ?? String(centers[0]?.id ?? ''));
    const [areaId, setAreaId] = useState('');
    const [memberAddError, setMemberAddError] = useState('');
    const relevantAreas = useMemo(() => areas.filter(a => String(a.center_id) === centerId), [areas, centerId]);
    const relevantSocieties = useMemo(() => societies.filter(s => String(s.center_id) === centerId && areaId !== '' && String(s.sampark_area_id ?? '') === areaId), [societies, centerId, areaId]);
    const filter = (key:string, value:string) => router.get('/registration/families', { ...filters, [key]:value }, { preserveState:true, replace:true });
    const memberComplete = (m:MemberDraft) => m.name.trim() !== '' && m.age !== '' && m.relationship.trim() !== '';
    const addMember = () => {
        const last = members[members.length - 1];
        if (last && !memberComplete(last)) {
            setMemberAddError('Complete the current member Name, Age and Relationship before adding another member.');
            return;
        }
        setMemberAddError('');
        setMembers([...members, newMember()]);
    };
    return <AppLayout title="Sankalp Families">
        <div className="grid gap-5 xl:grid-cols-[1fr_390px]">
            <section className="hf-card p-4 md:p-5 overflow-x-auto">
                <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div><h2 className="font-extrabold text-lg">Family Register</h2><p className="text-sm text-[#76647e]">Imported and manually registered families; Family ID is the primary reference.</p></div>
                    <span className="hf-badge">{families.total} families</span>
                </div>
                <div className="grid gap-2 md:grid-cols-5 mb-4">
                    <div className="relative"><Search size={16} className="absolute left-3 top-3 text-[#8c7994]"/><input className="hf-input pl-9" placeholder="ID, head, mobile" defaultValue={filters.search ?? ''} onKeyDown={e => { if(e.key==='Enter') filter('search',(e.target as HTMLInputElement).value); }}/></div>
                    <select className="hf-input" value={filters.center_id ?? ''} onChange={e=>filter('center_id',e.target.value)}><option value="">All centers</option>{centers.map(c=><option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}</select>
                    <select className="hf-input" value={filters.source ?? ''} onChange={e=>filter('source',e.target.value)}><option value="">All sources</option><option value="global">SMVS Global</option><option value="manual">Manual</option><option value="karyakar_reported">Karyakar Reported</option></select>
                    <select className="hf-input" value={filters.status ?? ''} onChange={e=>filter('status',e.target.value)}><option value="">All status</option><option value="active">Active</option><option value="pending_verification">Pending Verification</option><option value="inactive">Inactive</option></select>
                    <select className="hf-input" value={filters.gender ?? ''} onChange={e=>filter('gender',e.target.value)}><option value="">Any member gender</option><option value="male">Male member</option><option value="female">Female member</option></select>
                </div>
                <div className="hf-table-scroll"><table className="hf-table hf-mobile-table min-w-[1040px]"><thead><tr><th>Family</th><th>Head / Contact</th><th>Center</th><th>Members</th><th>Area / Society</th><th>Group</th><th>Source</th><th>Status</th></tr></thead><tbody>
                    {families.data.map(f=><tr key={f.id}><td><Link className="font-bold text-[#6a1b9a]" href={`/registration/families/${f.id}`}>{f.external_family_id ?? f.manual_reference}</Link></td><td><div className="font-semibold">{f.head_name}</div><div className="text-xs text-[#7d6c84]">{f.head_mobile?<a className="font-bold text-[#6a1b9a]" href={`tel:${f.head_mobile}`}>Call {f.head_mobile}</a>:'No mobile'}</div></td><td>{f.center.code}</td><td><div>{f.members_count} total</div><div className="text-xs">M {f.male_count} / F {f.female_count}</div></td><td>{f.area?.name ?? '-'}<div className="text-xs text-[#7d6c84]">{f.society?.name ?? ''}</div></td><td>{f.group_assignments?.length?<Link className="hf-badge" href={`/assignments/groups/${f.group_assignments[0].group.id}`}>{f.group_assignments[0].group.group_code} · {f.group_assignments[0].assignment_type}</Link>:<span className="text-xs text-[#76647e]">Unassigned</span>}</td><td><span className="hf-badge">{f.source === 'global' ? 'Global' : f.source === 'karyakar_reported' ? 'Karyakar Reported' : 'Manual'}</span></td><td className="capitalize">{f.status}</td></tr>)}
                    {families.data.length===0 && <tr><td colSpan={8} className="text-center text-[#7d6c84] py-8">No family records found.</td></tr>}
                </tbody></table></div>
                <div className="flex justify-between items-center mt-4 text-sm"><span>Page {families.current_page} / {families.last_page}</span><div className="flex gap-2">{families.prev_page_url && <Link className="hf-btn hf-btn-secondary" href={families.prev_page_url}>Previous</Link>}{families.next_page_url && <Link className="hf-btn hf-btn-secondary" href={families.next_page_url}>Next</Link>}</div></div>
            </section>

            <section className="hf-card p-5 h-fit">
                <h2 className="font-extrabold text-lg">Manual Family Registration</h2><p className="text-sm text-[#76647e] mb-4">Center is restricted to your permitted scope. A unique Manual Family reference is generated automatically.</p>
                <Form action="/registration/families" method="post" className="space-y-3" transform={data=>({...data, members})}>{({ errors, processing, reset }) => <>
                    <FormField label="Center" error={errors.center_id}><select name="center_id" className="hf-input" value={centerId} onChange={e=>{setCenterId(e.target.value);setAreaId('')}} required>{centers.map(c=><option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}</select></FormField>
                    <FormField label="Head of Family" error={errors.head_name}><input name="head_name" className="hf-input" required/></FormField>
                    <div className="grid grid-cols-2 gap-2"><FormField label="Mobile" error={errors.head_mobile}><input name="head_mobile" className="hf-input" inputMode="numeric" maxLength={10} pattern="[6-9][0-9]{9}" placeholder="10-digit mobile"/></FormField><FormField label="City / Village"><input name="city_village" className="hf-input"/></FormField></div>
                    <FormField label="Address"><textarea name="address" className="hf-input" rows={2}/></FormField>
                    <div className="grid grid-cols-2 gap-2"><FormField label="Sampark Area"><select name="sampark_area_id" className="hf-input" value={areaId} onChange={e=>setAreaId(e.target.value)}><option value="">-</option>{relevantAreas.map(a=><option key={a.id} value={a.id}>{a.name}</option>)}</select></FormField><FormField label="Society"><select name="society_id" className="hf-input" disabled={!areaId}><option value="">-</option>{relevantSocieties.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></FormField></div>
                    <div className="border-t border-[#eadff0] pt-3"><div className="flex justify-between items-center mb-2"><div><span className="font-bold text-sm">Family Members</span><p className="text-[11px] text-[#76647e]">If a member is marked Head, that member name becomes the Family head (Male or Female).</p></div><button type="button" className="hf-btn hf-btn-secondary text-xs" onClick={addMember}><Plus size={14} className="inline"/> Add</button></div>
                        {memberAddError&&<div className="mb-2 rounded-lg bg-amber-50 p-2 text-xs font-semibold text-amber-800">{memberAddError}</div>}
                        {errors.members&&<div className="mb-2 rounded-lg bg-red-50 p-2 text-xs font-semibold text-red-700">{errors.members}</div>}
                        <div className="space-y-3">{members.map((m,i)=><div key={i} className="rounded-xl bg-[#faf7fc] border border-[#eadff0] p-3 space-y-2"><div className="flex gap-2"><input className="hf-input" placeholder="Member name" value={m.name} onChange={e=>{setMemberAddError('');setMembers(members.map((x,j)=>j===i?{...x,name:e.target.value}:x))}}/>{members.length>1&&<button type="button" aria-label="Remove member" onClick={()=>setMembers(members.filter((_,j)=>j!==i))}><Trash2 size={17}/></button>}</div><div className="grid grid-cols-2 gap-2"><select className="hf-input" value={m.gender} onChange={e=>setMembers(members.map((x,j)=>j===i?{...x,gender:e.target.value as 'male'|'female'}:x))}><option value="male">Male</option><option value="female">Female</option></select><input className="hf-input" type="number" min="0" max="120" required placeholder="Age" value={m.age} onChange={e=>{setMemberAddError('');setMembers(members.map((x,j)=>j===i?{...x,age:e.target.value}:x))}}/></div><input className="hf-input" placeholder="Relationship" required value={m.relationship} onChange={e=>{setMemberAddError('');setMembers(members.map((x,j)=>j===i?{...x,relationship:e.target.value}:x))}}/><input className="hf-input" inputMode="numeric" maxLength={10} pattern="[6-9][0-9]{9}" placeholder="Member mobile (optional)" value={m.mobile} onChange={e=>setMembers(members.map((x,j)=>j===i?{...x,mobile:e.target.value}:x))}/><label className="text-xs flex gap-2"><input type="checkbox" checked={m.is_head} onChange={e=>setMembers(members.map((x,j)=>({...x,is_head:j===i?e.target.checked:false})))}/> Head member</label></div>)}</div>
                    </div>
                    <button className="hf-btn w-full" disabled={processing}>Register Family</button>
                </>}</Form>
            </section>
        </div>
    </AppLayout>;
}
