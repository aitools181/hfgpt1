import { Head, Link } from '@inertiajs/react';
import { Download, Filter } from 'lucide-react';
import AppLayout from '../../layouts/app-layout';
import LiveFilterForm from '../../components/live-filter-form';

type Option = { id:number; name?:string; code?:string; group_code?:string; full_name?:string };
type Filters = { center_id:number|null; group_id:number|null; karyakar_id:number|null; area_id:number|null; gender:string|null; category:string|null; status:string|null; date_from:string|null; date_to:string|null; female_scope_locked:boolean };
type Props = {
    report: { type:string; title:string; columns:Record<string,string>; rows:Record<string,any>[]; filters:Filters; truncated:boolean; row_limit:number };
    reportTypes: Record<string,string>;
    options: { centers:Option[]; groups:Option[]; karyakars:Option[]; areas:Option[]; categories:string[] };
};
export default function Reports({report,reportTypes,options}:Props){
    const exportHref = `/monitoring/reports/export?${queryString(report.type, report.filters)}`;
    return <AppLayout title="Reports"><Head title="Reports"/>
        <section className="hf-card mb-5 p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div><div className="text-xs font-bold uppercase tracking-[.15em] text-[#7b5f87]">Required reporting suite</div><h2 className="text-xl font-black text-[#351342]">{report.title}</h2><p className="mt-1 text-sm text-[#716176]">Filters apply inside the signed-in user's Center/Zone/organization scope.</p></div><div className="flex gap-2"><Link href="/monitoring/analysis" className="hf-btn hf-btn-secondary">Analysis</Link><a href={exportHref} className="hf-btn inline-flex items-center gap-2"><Download size={16}/> Export CSV</a></div></div>
            <LiveFilterForm action="/monitoring/reports" className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <div><label className="hf-label">Report</label><select className="hf-input" name="report" defaultValue={report.type}>{Object.entries(reportTypes).map(([key,label])=><option key={key} value={key}>{label}</option>)}</select></div>
                <Select name="center_id" label="Center" value={report.filters.center_id ?? ''}><option value="">All permitted centers</option>{options.centers.map(o=><option key={o.id} value={o.id}>{o.name} ({o.code})</option>)}</Select>
                <Select name="group_id" label="Group" value={report.filters.group_id ?? ''}><option value="">All groups</option>{options.groups.map(o=><option key={o.id} value={o.id}>{o.group_code}</option>)}</Select>
                <Select name="karyakar_id" label="Karyakar" value={report.filters.karyakar_id ?? ''}><option value="">All Karyakars</option>{options.karyakars.map(o=><option key={o.id} value={o.id}>{o.full_name}</option>)}</Select>
                <Select name="area_id" label="Area" value={report.filters.area_id ?? ''}><option value="">All areas</option>{options.areas.map(o=><option key={o.id} value={o.id}>{o.name}</option>)}</Select>
                <Select name="gender" label="Gender" value={report.filters.gender ?? ''} disabled={report.filters.female_scope_locked}><option value="">All</option><option value="male">Male</option><option value="female">Female</option></Select>
                {report.filters.female_scope_locked && <input type="hidden" name="gender" value="female"/>}
                <Select name="category" label="Category" value={report.filters.category ?? ''}><option value="">All categories</option>{options.categories.map(c=><option key={c} value={c}>{c}</option>)}</Select>
                <Select name="status" label="Status" value={report.filters.status ?? ''}><option value="">All statuses</option><option value="active">Active</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="completed">Completed</option><option value="draft">Draft</option></Select>
                <div><label className="hf-label">From</label><input className="hf-input" type="date" name="date_from" defaultValue={report.filters.date_from ?? ''}/></div>
                <div><label className="hf-label">To</label><input className="hf-input" type="date" name="date_to" defaultValue={report.filters.date_to ?? ''}/></div>
                <div className="flex items-end gap-2"><button className="hf-btn inline-flex items-center gap-2" type="submit"><Filter size={16}/> Generate</button><Link className="hf-btn hf-btn-secondary" href={`/monitoring/reports?report=${report.type}`}>Reset</Link></div>
            </LiveFilterForm>
        </section>
        {report.filters.female_scope_locked && <div className="mb-5 rounded-xl border border-fuchsia-200 bg-fuchsia-50 p-4 text-sm font-semibold text-fuchsia-900">BN Karyalay gender analysis is fixed to Female.</div>}
        <section className="hf-card overflow-hidden"><div className="flex items-center justify-between gap-3 border-b border-[#eadff0] p-4"><div><div className="font-extrabold">{report.title}</div><div className="text-xs text-[#75667a]">{report.truncated ? `Showing first ${report.row_limit} rows - use CSV for the full dataset` : `${report.rows.length} row(s)`}</div></div><a href={exportHref} className="hf-btn hf-btn-secondary inline-flex items-center gap-2"><Download size={15}/> CSV</a></div><div className="overflow-x-auto"><div className="hf-table-scroll"><table className="hf-table hf-mobile-table"><thead><tr>{Object.values(report.columns).map((label)=><th key={label}>{label}</th>)}</tr></thead><tbody>{report.rows.length===0?<tr><td colSpan={Object.keys(report.columns).length}>No data found for the selected filters.</td></tr>:report.rows.map((row,index)=><tr key={index}>{Object.keys(report.columns).map(key=><td key={key}>{format(row[key])}</td>)}</tr>)}</tbody></table></div></div></section>
    </AppLayout>
}
function Select({label,children,...props}:any){return <div><label className="hf-label">{label}</label><select className="hf-input" {...props}>{children}</select></div>}
function format(v:any){if(v===null||v===undefined||v==='')return '-';if(typeof v==='boolean')return v?'Yes':'No';return String(v)}
function queryString(type:string, filters:Filters){const p=new URLSearchParams();p.set('report',type);Object.entries(filters).forEach(([k,v])=>{if(k==='female_scope_locked')return;if(v!==null&&v!==undefined&&v!=='')p.set(k,String(v));});return p.toString()}
