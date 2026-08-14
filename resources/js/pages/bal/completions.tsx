import { Head, useForm } from '@inertiajs/react';
import AppLayout from '../../layouts/app-layout';

export default function BalCompletions({reports,options}:any){
 const f=useForm({group_id:'',society_id:'',family_id:'',families_visited:1,families_completed:1,mobile:'',family_name:'',family_details:'',completion_date:new Date().toISOString().slice(0,10)});
 const group=options.groups.find((g:any)=>String(g.id)===String(f.data.group_id));
 const societies=options.societies.filter((s:any)=>String(s.center_id)===String(group?.center_id??''));
 const families=options.families.filter((x:any)=>String(x.center_id)===String(group?.center_id??'')&&(!f.data.society_id||x.society_id===null||String(x.society_id)===String(f.data.society_id)));
 return <AppLayout title="Bal Pravruti Entry / Completion"><Head title="Bal Completion"/>
  <div className="grid gap-5 2xl:grid-cols-[430px_1fr]">
   <form className="hf-card p-5 space-y-3" onSubmit={e=>{e.preventDefault();if(f.data.group_id)f.post(`/bal-pravruti/groups/${f.data.group_id}/completions`,{onSuccess:()=>f.reset('society_id','family_id','mobile','family_name','family_details')})}}><div><div className="text-xs font-bold uppercase tracking-[.14em] text-[#7b5f87]">Assigned Sanchalak only</div><h2 className="text-lg font-extrabold">Submit Family Completion Report</h2></div>
    <Field label="Bal Group"><select className="hf-input" value={f.data.group_id} onChange={e=>{f.setData('group_id',e.target.value);f.setData('society_id','');f.setData('family_id','')}}><option value="">Select assigned Group</option>{options.groups.map((g:any)=><option key={g.id} value={g.id}>{g.group_code} - {g.center?.name}</option>)}</select></Field>
    <Field label="Society"><select className="hf-input" value={f.data.society_id} onChange={e=>{f.setData('society_id',e.target.value);f.setData('family_id','')}}><option value="">Select Society</option>{societies.map((s:any)=><option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
    <Field label="Known Sankalp Family (optional)"><select className="hf-input" value={f.data.family_id} onChange={e=>f.setData('family_id',e.target.value)}><option value="">Not linked / enter details below</option>{families.map((x:any)=><option key={x.id} value={x.id}>{x.external_family_id??x.manual_reference} - {x.head_name}</option>)}</select></Field>
    <div className="grid grid-cols-2 gap-3"><Field label="Families Visited"><input className="hf-input" type="number" min="1" value={f.data.families_visited} onChange={e=>f.setData('families_visited',Number(e.target.value))}/></Field><Field label="Families Completed"><input className="hf-input" type="number" min="0" value={f.data.families_completed} onChange={e=>f.setData('families_completed',Number(e.target.value))}/></Field></div>
    <Field label="Family / Head Name"><input className="hf-input" value={f.data.family_name} onChange={e=>f.setData('family_name',e.target.value)} placeholder="Relevant family name"/></Field>
    <Field label="Mobile (optional)"><input className="hf-input" value={f.data.mobile} onChange={e=>f.setData('mobile',e.target.value)} placeholder="Optional mobile number"/></Field>
    <Field label="Relevant Family Details"><textarea className="hf-input min-h-28" value={f.data.family_details} onChange={e=>f.setData('family_details',e.target.value)} placeholder="Visit/completion and relevant family details"/></Field>
    <Field label="Completion Date"><input className="hf-input" type="date" value={f.data.completion_date} onChange={e=>f.setData('completion_date',e.target.value)}/></Field>
    {Object.keys(f.errors).length>0&&<div className="rounded-xl bg-red-50 p-3 text-sm text-red-800">{Object.values(f.errors).join(' ')}</div>}<button className="hf-btn" disabled={f.processing||!f.data.group_id}>Submit Completion</button>
   </form>
   <section className="hf-card p-5 overflow-x-auto"><h2 className="font-extrabold">My Bal Completion History</h2><table className="hf-table mt-3"><thead><tr><th>Date</th><th>Group</th><th>Society</th><th>Family</th><th>Visited</th><th>Completed</th><th>Mobile</th><th>Details</th></tr></thead><tbody>{reports.map((r:any)=><tr key={r.id}><td>{String(r.completion_date).slice(0,10)}</td><td className="font-bold">{r.group?.group_code}</td><td>{r.society?.name}</td><td>{r.family?.head_name??r.family_name??'-'}</td><td>{r.families_visited}</td><td>{r.families_completed}</td><td>{r.mobile??'-'}</td><td className="max-w-md whitespace-pre-wrap">{r.family_details}</td></tr>)}</tbody></table></section>
  </div>
 </AppLayout>;
}
function Field({label,children}:{label:string;children:any}){return <div><label className="hf-label">{label}</label>{children}</div>}
