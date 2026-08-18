import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '../../layouts/app-layout';

type RemoteOption = Record<string, any>;

export default function BalGroups({groups,options,canManage}:any){
 const f=useForm({center_id:'',sampark_area_id:'',society_id:'',sanchalak_karyakar_id:'',child_member_ids:['','',''],nirdeshak_user_id:'',nirikshak_user_id:''});
 const centerId=String(f.data.center_id);
 const areas=(options?.areas??[]).filter((x:any)=>String(x.center_id)===centerId);
 const societies=(options?.societies??[]).filter((x:any)=>String(x.center_id)===centerId&&(!f.data.sampark_area_id||String(x.sampark_area_id)===String(f.data.sampark_area_id)));
 const [childSearch,setChildSearch]=useState('');
 const [sanchalakSearch,setSanchalakSearch]=useState('');
 const [nirdeshakSearch,setNirdeshakSearch]=useState('');
 const [nirikshakSearch,setNirikshakSearch]=useState('');
 const [children,setChildren]=useState<RemoteOption[]>([]);
 const [sanchalaks,setSanchalaks]=useState<RemoteOption[]>([]);
 const [nirdeshaks,setNirdeshaks]=useState<RemoteOption[]>([]);
 const [nirikshaks,setNirikshaks]=useState<RemoteOption[]>([]);

 useEffect(()=>{
   setChildren([]);setSanchalaks([]);setNirdeshaks([]);setNirikshaks([]);
   setChildSearch('');setSanchalakSearch('');setNirdeshakSearch('');setNirikshakSearch('');
 },[centerId]);

 useRemoteOptions(centerId,'child',childSearch,setChildren);
 useRemoteOptions(centerId,'sanchalak',sanchalakSearch,setSanchalaks);
 useRemoteOptions(centerId,'nirdeshak',nirdeshakSearch,setNirdeshaks);
 useRemoteOptions(centerId,'nirikshak',nirikshakSearch,setNirikshaks);

 const setChild=(index:number,value:string)=>{const next=[...f.data.child_member_ids];next[index]=value;f.setData('child_member_ids',next)};
 const groupRows=Array.isArray(groups)?groups:(groups?.data??[]);
 const links=Array.isArray(groups)?[]:(groups?.links??[]);

 return <AppLayout title="Bal Pravruti Groups"><Head title="Bal Groups"/>
  <div className={canManage?'grid gap-5 2xl:grid-cols-[460px_1fr]':''}>
   {canManage&&<form className="hf-card p-5 space-y-3" onSubmit={e=>{e.preventDefault();f.post('/bal-pravruti/groups')}}><div><div className="text-xs font-bold uppercase tracking-[.14em] text-[#7b5f87]">Exact composition rule</div><h2 className="text-lg font-extrabold">Create 3 Children + 1 Sanchalak Group</h2><p className="mt-1 text-xs text-[#78677e]">Large member lists are searched on demand so this page remains fast at production scale.</p></div>
    <Field label="Center"><select className="hf-input" value={f.data.center_id} onChange={e=>{f.setData('center_id',e.target.value);f.setData('sampark_area_id','');f.setData('society_id','');f.setData('sanchalak_karyakar_id','');f.setData('child_member_ids',['','','']);f.setData('nirdeshak_user_id','');f.setData('nirikshak_user_id','')}}><option value="">Select Center</option>{options?.centers.map((c:any)=><option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}</select></Field>
    <div className="grid grid-cols-2 gap-3"><Field label="Sampark Area"><select className="hf-input" value={f.data.sampark_area_id} onChange={e=>{f.setData('sampark_area_id',e.target.value);f.setData('society_id','')}}><option value="">Optional</option>{areas.map((a:any)=><option key={a.id} value={a.id}>{a.name}</option>)}</select></Field><Field label="Society"><select className="hf-input" value={f.data.society_id} onChange={e=>f.setData('society_id',e.target.value)}><option value="">Optional</option>{societies.map((s:any)=><option key={s.id} value={s.id}>{s.name}</option>)}</select></Field></div>

    <SearchBox label="Search Sanchalak" value={sanchalakSearch} onChange={setSanchalakSearch} disabled={!centerId}/>
    <Field label="Sanchalak (Approved Sankalp Karyakar)"><select className="hf-input" value={f.data.sanchalak_karyakar_id} onChange={e=>f.setData('sanchalak_karyakar_id',e.target.value)} disabled={!centerId}><option value="">Select linked Sanchalak</option>{sanchalaks.map((k:any)=><option key={k.id} value={k.id}>{k.full_name} · {k.gender} · {k.category}</option>)}</select></Field>

    <SearchBox label="Search children by name / Family ID / head" value={childSearch} onChange={setChildSearch} disabled={!centerId}/>
    {[0,1,2].map(i=><Field key={i} label={`Child ${i+1} (Age 0-12)`}><select className="hf-input" value={f.data.child_member_ids[i]} onChange={e=>setChild(i,e.target.value)} disabled={!centerId}><option value="">Select child</option>{children.map((c:any)=><option key={c.id} value={c.id}>{c.name} · {c.gender} · age {c.age} · {c.family_reference??c.family_head}</option>)}</select></Field>)}

    <div className="grid grid-cols-2 gap-3"><div><SearchBox label="Search Nirdeshak" value={nirdeshakSearch} onChange={setNirdeshakSearch} disabled={!centerId}/><Field label="Nirdeshak"><select className="hf-input" value={f.data.nirdeshak_user_id} onChange={e=>f.setData('nirdeshak_user_id',e.target.value)} disabled={!centerId}><option value="">Optional</option>{nirdeshaks.map((u:any)=><option key={u.id} value={u.id}>{u.name}</option>)}</select></Field></div><div><SearchBox label="Search Nirikshak" value={nirikshakSearch} onChange={setNirikshakSearch} disabled={!centerId}/><Field label="Nirikshak"><select className="hf-input" value={f.data.nirikshak_user_id} onChange={e=>f.setData('nirikshak_user_id',e.target.value)} disabled={!centerId}><option value="">Optional</option>{nirikshaks.map((u:any)=><option key={u.id} value={u.id}>{u.name}</option>)}</select></Field></div></div>
    {Object.keys(f.errors).length>0&&<div className="rounded-xl bg-red-50 p-3 text-sm text-red-800">{Object.values(f.errors).join(' ')}</div>}<button className="hf-btn" disabled={f.processing}>Create Bal Group</button>
   </form>}
   <section className="hf-card p-5 overflow-x-auto"><div className="mb-3 flex items-center justify-between gap-3"><div><h2 className="font-extrabold">Accessible Bal Groups</h2><p className="text-xs text-[#78677e]">Nirdeshak/Nirikshak see assigned Groups; Sanchalak sees own Groups.</p></div><Link href="/bal-pravruti" className="hf-btn hf-btn-secondary">Dashboard</Link></div><div className="hf-table-scroll"><table className="hf-table hf-mobile-table"><thead><tr><th>Group</th><th>Center</th><th>Sanchalak</th><th>Children</th><th>Supervision</th><th>Reports</th><th>Status</th></tr></thead><tbody>{groupRows.map((g:any)=><tr key={g.id}><td><Link href={`/bal-pravruti/groups/${g.id}`} className="font-bold text-[#6a1b9a]">{g.group_code}</Link></td><td>{g.center?.name}</td><td>{g.sanchalak?.full_name}<div className="text-xs text-[#78677e]">{g.sanchalak?.category}</div></td><td>{g.children_count} / 3</td><td>{g.supervisors.length?g.supervisors.map((s:any)=><div key={`${s.role_slug}-${s.user.id}`} className="text-xs"><b>{s.role_slug}:</b> {s.user.name}</div>):'-'}</td><td>{g.completion_reports_count}</td><td><span className="hf-badge">{g.status}</span></td></tr>)}</tbody></table></div>{links.length>0&&<div className="mt-4 flex flex-wrap gap-2">{links.map((link:any,index:number)=>link.url?<Link preserveScroll key={index} href={link.url} className={`rounded-lg border px-3 py-2 text-xs font-semibold ${link.active?'bg-[#6a1b9a] text-white':'bg-white'}`} dangerouslySetInnerHTML={{__html:link.label}}/>:<span key={index} className="rounded-lg border px-3 py-2 text-xs text-gray-400" dangerouslySetInnerHTML={{__html:link.label}}/>)}</div>}</section>
  </div>
 </AppLayout>;
}

function useRemoteOptions(centerId:string,type:string,query:string,setter:(rows:RemoteOption[])=>void){
 useEffect(()=>{
   if(!centerId){setter([]);return;}
   const controller=new AbortController();
   const timer=window.setTimeout(async()=>{
     try{
       const params=new URLSearchParams({center_id:centerId,type});
       if(query.trim())params.set('q',query.trim());
       const response=await fetch(`/bal-pravruti/groups/options/search?${params.toString()}`,{headers:{Accept:'application/json'},credentials:'same-origin',signal:controller.signal});
       if(!response.ok)throw new Error('Search failed');
       const body=await response.json();
       setter(Array.isArray(body.results)?body.results:[]);
     }catch(error:any){if(error?.name!=='AbortError')setter([]);}
   },250);
   return()=>{window.clearTimeout(timer);controller.abort();};
 },[centerId,type,query,setter]);
}
function SearchBox({label,value,onChange,disabled}:{label:string;value:string;onChange:(v:string)=>void;disabled:boolean}){return <div><label className="hf-label">{label}</label><input className="hf-input" value={value} onChange={e=>onChange(e.target.value)} disabled={disabled} placeholder={disabled?'Select Center first':'Type to search (max 50 results)'}/></div>}
function Field({label,children}:{label:string;children:any}){return <div><label className="hf-label">{label}</label>{children}</div>}
