import { Head, useForm, usePage } from '@inertiajs/react';
import type { PageProps } from '../../types';

export default function Login() {
    const page = usePage<PageProps>();
    const form = useForm({ email: '', password: '', remember: false });
    return <>
        <Head title="Login" />
        <div className="min-h-screen grid place-items-center p-5 bg-[radial-gradient(circle_at_top,#eadcf0,#f8f4fa_50%)]">
            <div className="w-full max-w-md hf-card overflow-hidden">
                <div className="hf-brand p-7 text-white">
                    <div className="text-xs uppercase tracking-[.22em] opacity-80">SMVS</div>
                    <h1 className="text-3xl font-black mt-2">Happy Family Portal</h1>
                    <p className="text-sm mt-2 opacity-90">Happy Families. Stronger Society. Better Tomorrow.</p>
                </div>
                <form className="p-7 space-y-5" onSubmit={(e) => { e.preventDefault(); form.post('/login'); }}>
                    {page.props.flash.success && <div className="rounded-xl border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-800">{page.props.flash.success}</div>}
                    {page.props.flash.error && <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800">{page.props.flash.error}</div>}
                    <div><label className="hf-label">Email</label><input className="hf-input" type="email" autoComplete="username" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoFocus />{form.errors.email && <div className="hf-error">{form.errors.email}</div>}</div>
                    <div><label className="hf-label">Password</label><input className="hf-input" type="password" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />{form.errors.password && <div className="hf-error">{form.errors.password}</div>}</div>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} /> Remember me</label>
                    <button className="hf-btn w-full" disabled={form.processing}>Login</button>
                </form>
            </div>
        </div>
    </>;
}
