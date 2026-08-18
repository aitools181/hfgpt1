import { Head, useForm, usePage } from '@inertiajs/react';
import { LockKeyhole, Mail, ShieldCheck } from 'lucide-react';
import type { PageProps } from '../../types';

export default function Login() {
    const page = usePage<PageProps>();
    const form = useForm({ email: '', password: '', remember: false });
    return <>
        <Head title="Login" />
        <div className="hf-login-screen">
            <div className="hf-login-panel">
                <div className="hf-login-hero">
                    <img src="/app-icon.svg" alt="SMVS Happy Family" className="hf-login-icon" />
                    <div className="min-w-0">
                        <div className="text-xs uppercase tracking-[.22em] opacity-80">SMVS</div>
                        <h1 className="mt-1 text-2xl font-black sm:text-3xl">Happy Family Portal</h1>
                        <p className="mt-1 text-sm opacity-90">Happy Families. Stronger Society. Better Tomorrow.</p>
                    </div>
                </div>
                <form className="hf-login-form" onSubmit={(e) => { e.preventDefault(); form.post('/login'); }}>
                    <div className="mb-1 flex items-center gap-2 text-xs font-bold text-[#70507c]"><ShieldCheck size={16}/> Secure role-based sign in</div>
                    {page.props.flash.success && <div className="rounded-xl border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-800">{page.props.flash.success}</div>}
                    {page.props.flash.error && <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800">{page.props.flash.error}</div>}
                    <div>
                        <label className="hf-label">Email</label>
                        <div className="hf-input-with-icon"><Mail size={18}/><input type="email" inputMode="email" autoCapitalize="none" autoComplete="username" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoFocus placeholder="name@example.com" /></div>
                        {form.errors.email && <div className="hf-error">{form.errors.email}</div>}
                    </div>
                    <div>
                        <label className="hf-label">Password</label>
                        <div className="hf-input-with-icon"><LockKeyhole size={18}/><input type="password" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} placeholder="Enter password" /></div>
                        {form.errors.password && <div className="hf-error">{form.errors.password}</div>}
                    </div>
                    <label className="flex min-h-11 items-center gap-3 text-sm font-semibold text-[#5d4b63]"><input className="h-5 w-5 accent-[#6a1b9a]" type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} /> Remember me</label>
                    <button className="hf-btn w-full" disabled={form.processing}>{form.processing ? 'Signing in...' : 'Login'}</button>
                </form>
            </div>
        </div>
    </>;
}
