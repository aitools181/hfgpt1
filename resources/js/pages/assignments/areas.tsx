import { Form, Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AppLayout from '../../layouts/app-layout';
import FormField from '../../components/form-field';

type Center={id:number;name:string;code:string};
type Area={id:number;center_id:number;name:string};
type Society={id:number;center_id:number;sampark_area_id:number;name:string};
type RecordItem={id:number;center_id:number;label:string;sampark_area_id?:number|null;society_id?:number|null;area?:{id:number;name:string}|null;society?:{id:number;name:string}|null};
type Props={centers:Center[]};

async function search<T>(params:Record<string,string>):Promise<T[]> {
 const response=await fetch(`/assignments/areas/options/search?${new URLSearchParams(params).toString()}`,{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
 if(!response.ok) throw new Error(`Option request failed (${response.status})`);
 return response.json() as Promise<T[]>;
}

export default function Areas({centers}:Props){
 const [centerId,setCenterId]=useState(String(centers[0]?.id??''));
 const [type,setType]=useState('group');
 const [recordId,setRecordId]=useState('');
 const [areaId,setAreaId]=useState('');
 const [recordSearch,setRecordSearch]=useState('');
 const [areaSearch,setAreaSearch]=useState('');
 const [records,setRecords]=useState<RecordItem[]>([]);
 const [areas,setAreas]=useState<Area[]>([]);
 const [societies,setSocieties]=useState<Society[]>([]);
 const [loadError,setLoadError]=useState('');

 useEffect(()=>{
  if(!centerId){setRecords([]);setAreas([]);return;}
  const timer=window.setTimeout(()=>{
   setLoadError('');
   Promise.all([
    search<RecordItem>({type,center_id:centerId,q:recordSearch}),
    search<Area>({type:'area',center_id:centerId,q:areaSearch}),
   ]).then(([r,a])=>{setRecords(r);setAreas(a)}).catch(()=>setLoadError('Could not load assignment options. Please retry.'));
  },250);
  return()=>window.clearTimeout(timer);
 },[centerId,type,recordSearch,areaSearch]);

 useEffect(()=>{
  if(!centerId||!areaId){setSocieties([]);return;}
  search<Society>({type:'society',center_id:centerId,area_id:areaId}).then(setSocieties).catch(()=>setLoadError('Could not load Societies.'));
 },[centerId,areaId]);

 const selected=useMemo(()=>records.find(r=>String(r.id)===recordId),[records,recordId]);
 useEffect(()=>{
  if(selected?.sampark_area_id){setAreaId(String(selected.sampark_area_id));} else if(selected){setAreaId('');}
 },[selected?.id]);
 const center=centers.find(c=>String(c.id)===centerId);

 return <AppLayout title="Sampark Area & Society"><Head title="Area & Society"/><div className="grid gap-5 lg:grid-cols-[.7fr_1.3fr]">
   <section className="hf-card p-5"><h2 className="font-extrabold text-lg">Assign / Change</h2><p className="text-sm text-[#76647e] mb-4">Group, Karyakar and Family Area/Society changes require a reason and are written to Activity/Audit Logs.</p>{loadError&&<div className="mb-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{loadError}</div>}<Form action="/assignments/areas" method="post" className="space-y-3">{({errors,processing})=><><FormField label="Center"><select className="hf-input" value={centerId} onChange={e=>{setCenterId(e.target.value);setRecordId('');setAreaId('');setRecordSearch('')}}>{centers.map(c=><option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}</select></FormField><FormField label="Record Type"><select name="record_type" className="hf-input" value={type} onChange={e=>{setType(e.target.value);setRecordId('');setRecordSearch('');setAreaId('')}}><option value="group">Group</option><option value="karyakar">Karyakar</option><option value="family">Sankalp Family</option></select></FormField><FormField label="Find Record"><input className="hf-input" value={recordSearch} onChange={e=>setRecordSearch(e.target.value)} placeholder={`Search ${type}`}/></FormField><FormField label="Record" error={errors.record_id}><select name="record_id" className="hf-input" value={recordId} onChange={e=>setRecordId(e.target.value)} required><option value="">Select</option>{records.map(r=><option key={r.id} value={r.id}>{r.label}</option>)}</select></FormField><FormField label="Find Area"><input className="hf-input" value={areaSearch} onChange={e=>setAreaSearch(e.target.value)} placeholder="Search area"/></FormField><FormField label="Sampark Area" error={errors.sampark_area_id}><select name="sampark_area_id" className="hf-input" value={areaId} onChange={e=>setAreaId(e.target.value)} required><option value="">Select Area</option>{areas.map(a=><option key={a.id} value={a.id}>{a.name}</option>)}</select></FormField><FormField label="Society"><select name="society_id" className="hf-input"><option value="">No Society</option>{societies.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></FormField><FormField label="Reason / Change Note" error={errors.reason}><textarea name="reason" className="hf-input" rows={3} required/></FormField><button className="hf-btn w-full" disabled={processing}>Save Assignment</button></>}</Form></section>
   <section className="hf-card p-5 overflow-x-auto"><h2 className="font-extrabold text-lg">Current Search Results</h2><p className="text-xs text-[#76647e] mt-1">Showing a bounded on-demand result set for {center?.code??'the selected Center'}; search to find any additional record.</p><div className="hf-table-scroll"><table className="hf-table hf-mobile-table min-w-[760px] mt-4"><thead><tr><th>Type</th><th>Record</th><th>Center</th><th>Area</th><th>Society</th></tr></thead><tbody>{records.map(r=><tr key={`${type}-${r.id}`}><td className="capitalize">{type}</td><td><b>{r.label}</b></td><td>{center?.code}</td><td>{r.area?.name??'Unassigned'}</td><td>{r.society?.name??'-'}</td></tr>)}</tbody></table></div></section>
 </div></AppLayout>;
}
