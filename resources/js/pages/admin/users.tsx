import { Head, useForm } from '@inertiajs/react';
import AppLayout from '../../layouts/app-layout';

export default function Users({users,roles,zones,centers,karyakars}:any){
 const f=useForm({name:'',email:'',password:'',status:'active',role_id:'',zone_id:'',center_id:'',karyakar_id:''});
 const role=roles.find((r:any)=>String(r.id)===String(f.data.role_id));
 const eligibleKaryakars=karyakars.filter((k:any)=>String(k.center_id)===String(f.data.center_id)&&(!k.user_id));
 return <AppLayout title="User & Role Management"><Head title="Users"/><div className="grid gap-5 xl:grid-cols-[420px_1fr]">
  <form className="hf-card p-5 space-y-3" onSubmit={e=>{e.preventDefault();f.post('/admin/users',{onSuccess:()=>f.reset()})}}>
   <h2 className="font-extrabold">Create User</h2>
   <input className="hf-input" placeholder="Full name" value={f.data.name} onChange={e=>f.setData('name',e.target.value)}/>
   <input className="hf-input" placeholder="Email" type="email" value={f.data.email} onChange={e=>f.setData('email',e.target.value)}/>
   <input className="hf-input" placeholder="Temporary password (12+ chars)" type="password" value={f.data.password} onChange={e=>f.setData('password',e.target.value)}/>
   <select className="hf-input" value={f.data.role_id} onChange={e=>{f.setData('role_id',e.target.value);f.setData('karyakar_id','')}}><option value="">Select role</option>{roles.map((r:any)=><option key={r.id} value={r.id}>{r.name}</option>)}</select>
   <select className="hf-input" value={f.data.zone_id} onChange={e=>f.setData('zone_id',e.target.value)}><option value="">Zone (if applicable)</option>{zones.map((z:any)=><option key={z.id} value={z.id}>{z.name}</option>)}</select>
   <select className="hf-input" value={f.data.center_id} onChange={e=>{f.setData('center_id',e.target.value);f.setData('karyakar_id','')}}><option value="">Center (if applicable)</option>{centers.map((c:any)=><option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}</select>
   {['karyakar','sanchalak'].includes(role?.slug)&&<div><label className="hf-label">Link Approved Karyakar</label><select className="hf-input" value={f.data.karyakar_id} onChange={e=>f.setData('karyakar_id',e.target.value)}><option value="">Select Karyakar</option>{eligibleKaryakars.map((k:any)=><option key={k.id} value={k.id}>{k.karyakar_reference} - {k.full_name}</option>)}</select><p className="text-xs text-[#76647e] mt-1">Karyakar links enable own field assignments; Sanchalak links are required for Bal Pravruti Group assignment and completion reporting.</p></div>}
   <button className="hf-btn">Create User</button>
  </form>
  <div className="hf-card p-5 overflow-x-auto"><table className="hf-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Scope</th><th>Status</th></tr></thead><tbody>{users.map((u:any)=>{const r=u.roles[0]; return <tr key={u.id}><td>{u.name}</td><td>{u.email}</td><td>{r?.name ?? '-'}</td><td>{r?.center_id ? `Center #${r.center_id}` : r?.zone_id ? `Zone #${r.zone_id}` : 'Organization'}</td><td><span className="hf-badge">{u.status}</span></td></tr>})}</tbody></table></div>
 </div></AppLayout>
}
