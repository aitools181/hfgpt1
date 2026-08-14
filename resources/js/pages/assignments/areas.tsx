import { Form, Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppLayout from '../../layouts/app-layout';
import FormField from '../../components/form-field';

type Center={id:number;name:string;code:string};
type Area={id:number;center_id:number;name:string};
type Society={id:number;center_id:number;sampark_area_id:number;name:string};
type RecordItem={id:number;center_id:number;label:string;area?:{id:number;name:string}|null;society?:{id:number;name:string}|null};
type Props={centers:Center[];areas:Area[];societies:Society[];groups:any[];karyakars:any[];families:any[]};

export default function Areas({centers,areas,societies,groups,karyakars,families}:Props){
 const [type,setType]=useState('group'); const [recordId,setRecordId]=useState(''); const [areaId,setAreaId]=useState('');
 const records:RecordItem[]=useMemo(()=>type==='group'?groups.map(g=>({...g,label:g.group_code})):type==='karyakar'?karyakars.map(k=>({...k,label:`${k.karyakar_reference} - ${k.full_name}`})):families.map(f=>({...f,label:`${f.external_family_id??f.manual_reference} - ${f.head_name}`})),[type,groups,karyakars,families]);
 const selected=records.find(r=>String(r.id)===recordId); const centerId=selected?.center_id; const availableAreas=areas.filter(a=>!centerId||a.center_id===centerId); const availableSocieties=societies.filter(s=>String(s.sampark_area_id)===areaId);
 return <AppLayout title="Sampark Area & Society"><Head title="Area & Society"/><div className="grid gap-5 lg:grid-cols-[.7fr_1.3fr]">
   <section className="hf-card p-5"><h2 className="font-extrabold text-lg">Assign / Change</h2><p className="text-sm text-[#76647e] mb-4">Group, Karyakar and Family Area/Society changes require a reason and are written to Activity/Audit Logs.</p><Form action="/assignments/areas" method="post" className="space-y-3">{({errors,processing})=><><FormField label="Record Type"><select name="record_type" className="hf-input" value={type} onChange={e=>{setType(e.target.value);setRecordId('');setAreaId('')}}><option value="group">Group</option><option value="karyakar">Karyakar</option><option value="family">Sankalp Family</option></select></FormField><FormField label="Record" error={errors.record_id}><select name="record_id" className="hf-input" value={recordId} onChange={e=>{setRecordId(e.target.value);setAreaId('')}} required><option value="">Select</option>{records.map(r=><option key={r.id} value={r.id}>{r.label}</option>)}</select></FormField><FormField label="Sampark Area" error={errors.sampark_area_id}><select name="sampark_area_id" className="hf-input" value={areaId} onChange={e=>setAreaId(e.target.value)} required><option value="">Select Area</option>{availableAreas.map(a=><option key={a.id} value={a.id}>{a.name}</option>)}</select></FormField><FormField label="Society"><select name="society_id" className="hf-input"><option value="">No Society</option>{availableSocieties.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></FormField><FormField label="Reason / Change Note" error={errors.reason}><textarea name="reason" className="hf-input" rows={3} required/></FormField><button className="hf-btn w-full" disabled={processing}>Save Assignment</button></>}</Form></section>
   <section className="hf-card p-5 overflow-x-auto"><h2 className="font-extrabold text-lg">Current Assignments</h2><table className="hf-table min-w-[760px] mt-4"><thead><tr><th>Type</th><th>Record</th><th>Center</th><th>Area</th><th>Society</th></tr></thead><tbody>{records.slice(0,100).map(r=><tr key={`${type}-${r.id}`}><td className="capitalize">{type}</td><td><b>{r.label}</b></td><td>{centers.find(c=>c.id===r.center_id)?.code}</td><td>{r.area?.name??'Unassigned'}</td><td>{r.society?.name??'-'}</td></tr>)}</tbody></table><p className="text-xs text-[#76647e] mt-3">Showing up to 100 records for the selected type.</p></section>
 </div></AppLayout>;
}
