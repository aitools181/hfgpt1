import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AppLayout from '../../layouts/app-layout';
import FormField from '../../components/form-field';

type Center={id:number;name:string;code:string};
type Group={id:number;center_id:number;group_code:string;sampark_area_id?:number|null;society_id?:number|null;status:string};
type Karyakar={id:number;center_id:number;full_name:string;karyakar_reference:string};
type Area={id:number;center_id:number;name:string};
type Society={id:number;center_id:number;sampark_area_id:number;name:string};
type Target={id:number;name?:string|null;start_date:string;end_date:string;target_quantity:number;completed_quantity:number;remaining_quantity:number;completion_percentage:number;status:string;center:Center;group:{id:number;group_code:string};karyakar?:{id:number;full_name:string;karyakar_reference:string}|null;area:{id:number;name:string};society?:{id:number;name:string}|null};
type Pagination={data:Target[];total:number;current_page:number;last_page:number;prev_page_url:string|null;next_page_url:string|null};
type Props={targets:Pagination;centers:Center[];filters:Record<string,string|undefined>};

async function loadOptions<T>(params:Record<string,string>):Promise<T[]> {
 const query=new URLSearchParams(params);
 const response=await fetch(`/assignments/targets/options/search?${query.toString()}`,{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
 if(!response.ok) throw new Error(`Option request failed (${response.status})`);
 return response.json() as Promise<T[]>;
}

export default function Targets({targets,centers,filters}:Props){
 const [centerId,setCenterId]=useState(String(centers[0]?.id??''));
 const [groupId,setGroupId]=useState('');
 const [areaId,setAreaId]=useState('');
 const [groupSearch,setGroupSearch]=useState('');
 const [areaSearch,setAreaSearch]=useState('');
 const [groups,setGroups]=useState<Group[]>([]);
 const [karyakars,setKaryakars]=useState<Karyakar[]>([]);
 const [areas,setAreas]=useState<Area[]>([]);
 const [societies,setSocieties]=useState<Society[]>([]);
 const [optionError,setOptionError]=useState('');

 useEffect(()=>{
  if(!centerId){setGroups([]);setAreas([]);return;}
  const timer=window.setTimeout(()=>{
   setOptionError('');
   Promise.all([
    loadOptions<Group>({type:'group',center_id:centerId,q:groupSearch}),
    loadOptions<Area>({type:'area',center_id:centerId,q:areaSearch}),
   ]).then(([g,a])=>{setGroups(g);setAreas(a)}).catch(()=>setOptionError('Could not load assignment options. Please retry.'));
  },250);
  return()=>window.clearTimeout(timer);
 },[centerId,groupSearch,areaSearch]);

 useEffect(()=>{
  if(!centerId||!groupId){setKaryakars([]);return;}
  loadOptions<Karyakar>({type:'karyakar',center_id:centerId,group_id:groupId}).then(setKaryakars).catch(()=>setOptionError('Could not load Group Karyakars.'));
 },[centerId,groupId]);

 useEffect(()=>{
  if(!centerId||!areaId){setSocieties([]);return;}
  loadOptions<Society>({type:'society',center_id:centerId,area_id:areaId}).then(setSocieties).catch(()=>setOptionError('Could not load Societies.'));
 },[centerId,areaId]);

 const selectedGroup=useMemo(()=>groups.find(g=>String(g.id)===groupId),[groups,groupId]);
 useEffect(()=>{
  if(selectedGroup?.sampark_area_id){setAreaId(String(selectedGroup.sampark_area_id));}
 },[selectedGroup?.sampark_area_id]);

 const filter=(key:string,value:string)=>router.get('/assignments/targets',{...filters,[key]:value},{preserveState:true,replace:true});
 return <AppLayout title="Target Management"><Head title="Target Management"/><div className="space-y-5">
  <section className="hf-card p-5 overflow-x-auto"><div className="flex justify-between gap-3 mb-4"><div><h2 className="font-extrabold text-lg">Assigned Targets</h2><p className="text-sm text-[#76647e]">Target progress is recalculated from completed Home Visits within the target scope and date range.</p></div><span className="hf-badge">{targets.total} targets</span></div><div className="grid gap-2 md:grid-cols-2 mb-4"><select className="hf-input" value={filters.center_id??''} onChange={e=>filter('center_id',e.target.value)}><option value="">All centers</option>{centers.map(c=><option key={c.id} value={c.id}>{c.code}</option>)}</select><select className="hf-input" value={filters.status??''} onChange={e=>filter('status',e.target.value)}><option value="">All status</option><option value="active">Active</option><option value="completed">Completed</option><option value="closed">Closed</option></select></div><div className="hf-table-scroll"><table className="hf-table hf-mobile-table min-w-[980px]"><thead><tr><th>Target</th><th>Center / Group</th><th>Karyakar</th><th>Area / Society</th><th>Dates</th><th>Qty</th><th>Progress</th><th>Status</th></tr></thead><tbody>{targets.data.map(t=><tr key={t.id}><td><b>{t.name??`Target #${t.id}`}</b></td><td>{t.center.code}<div className="text-xs font-bold text-[#6a1b9a]">{t.group.group_code}</div></td><td>{t.karyakar?.full_name??'Whole Group'}</td><td>{t.area.name}{t.society&&<div className="text-xs">{t.society.name}</div>}</td><td>{String(t.start_date).slice(0,10)}<div className="text-xs">to {String(t.end_date).slice(0,10)}</div></td><td>{t.target_quantity}</td><td>{t.completed_quantity} / {t.target_quantity}<div className="text-xs">{t.completion_percentage}%</div></td><td><span className="hf-badge capitalize">{t.status}</span></td></tr>)}</tbody></table></div><div className="flex justify-between mt-4 text-sm"><span>Page {targets.current_page} / {targets.last_page}</span><div className="flex gap-2">{targets.prev_page_url&&<Link className="hf-btn hf-btn-secondary" href={targets.prev_page_url}>Previous</Link>}{targets.next_page_url&&<Link className="hf-btn hf-btn-secondary" href={targets.next_page_url}>Next</Link>}</div></div></section>
  <section className="hf-card p-5"><h2 className="font-extrabold text-lg">Assign Target</h2><p className="text-xs text-[#76647e] mt-1">Large catalogs are searched on demand so this page remains responsive at production scale.</p>{optionError&&<div className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{optionError}</div>}<Form action="/assignments/targets" method="post" className="grid gap-3 md:grid-cols-2 lg:grid-cols-4 mt-4">{({errors,processing})=><><FormField label="Center" error={errors.center_id}><select name="center_id" className="hf-input" value={centerId} onChange={e=>{setCenterId(e.target.value);setGroupId('');setAreaId('');setKaryakars([]);setSocieties([])}} required>{centers.map(c=><option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}</select></FormField><FormField label="Find Group"><input className="hf-input" value={groupSearch} onChange={e=>setGroupSearch(e.target.value)} placeholder="Search group code"/></FormField><FormField label="Group" error={errors.group_id}><select name="group_id" className="hf-input" value={groupId} onChange={e=>setGroupId(e.target.value)} required><option value="">Select Group</option>{groups.map(g=><option key={g.id} value={g.id}>{g.group_code} ({g.status})</option>)}</select></FormField><FormField label="Karyakar (optional)" error={errors.karyakar_id}><select name="karyakar_id" className="hf-input"><option value="">Whole Group</option>{karyakars.map(k=><option key={k.id} value={k.id}>{k.karyakar_reference} - {k.full_name}</option>)}</select></FormField><FormField label="Target Name"><input name="name" className="hf-input" placeholder="e.g. Sector 5 May Target"/></FormField><FormField label="Find Area"><input className="hf-input" value={areaSearch} onChange={e=>setAreaSearch(e.target.value)} placeholder="Search area"/></FormField><FormField label="Sampark Area" error={errors.sampark_area_id}><select name="sampark_area_id" className="hf-input" value={areaId} onChange={e=>setAreaId(e.target.value)} required><option value="">Select Area</option>{areas.map(a=><option key={a.id} value={a.id}>{a.name}</option>)}</select></FormField><FormField label="Society"><select name="society_id" className="hf-input"><option value="">All / No Society</option>{societies.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></FormField><FormField label="Start Date" error={errors.start_date}><input name="start_date" type="date" className="hf-input" required/></FormField><FormField label="End Date" error={errors.end_date}><input name="end_date" type="date" className="hf-input" required/></FormField><FormField label="Target Quantity" error={errors.target_quantity}><input name="target_quantity" type="number" min="1" className="hf-input" required/></FormField><div className="md:col-span-2 lg:col-span-3 flex items-end"><button className="hf-btn w-full" disabled={processing}>Assign Target</button></div></>}</Form></section>
 </div></AppLayout>;
}
