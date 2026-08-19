import { Head, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, LockKeyhole, Mail, ShieldCheck, Smartphone } from 'lucide-react';
import type { PageProps } from '../../types';

export default function Login() {
    const page = usePage<PageProps<{ authInfrastructureError?: string | null }>>();
    const form = useForm({ email: '', password: '', remember: false });
    return <>
        <Head title="Login" />
        <div className="hf-login-screen">
            <div className="hf-login-panel">
                <div className="hf-login-hero">
                    <div className="hf-login-hero-content">
                        <div className="flex items-center gap-3">
                            <img src="/app-icon.svg" alt="SMVS Happy Family" className="hf-login-icon" />
                            <div className="min-w-0">
                                <div className="text-[10px] font-extrabold uppercase tracking-[.22em] text-white/70">SMVS</div>
                                <h1 className="mt-1 text-2xl font-black sm:text-3xl">Happy Family</h1>
                                <p className="mt-1 text-sm font-semibold text-white/78">Campaign Management Portal</p>
                            </div>
                        </div>
                        <div className="hf-login-hero-copy">
                            <h2 className="text-xl font-black leading-tight sm:text-2xl">Happy Families. Stronger Society. Better Tomorrow.</h2>
                            <p className="mt-2 max-w-md text-sm leading-6 text-white/72">A focused workspace for registration, field execution, Bal Pravruti, monitoring and role-scoped reporting.</p>
                            <div className="mt-4 flex flex-wrap gap-2 text-[11px] font-bold text-white/85">
                                <span className="hf-login-chip"><ShieldCheck size={14}/> Role scoped</span>
                                <span className="hf-login-chip"><Smartphone size={14}/> Mobile ready</span>
                                <span className="hf-login-chip"><CheckCircle2 size={14}/> Secure workflow</span>
                            </div>
                        </div>
                    </div>
                </div>
                <form className="hf-login-form" onSubmit={(e) => { e.preventDefault(); form.post('/login'); }}>
                    <div className="mb-1">
                        <div className="text-[10px] font-extrabold uppercase tracking-[.14em] text-[#96869b]">Welcome back</div>
                        <h2 className="mt-1 text-2xl font-black text-[#302235]">Sign in to continue</h2>
                        <p className="mt-1 text-sm font-medium text-[#8b7c90]">Use your assigned portal credentials.</p>
                    </div>
                    {page.props.authInfrastructureError && <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-900">{page.props.authInfrastructureError}</div>}
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
                    <label className="flex min-h-11 items-center gap-3 text-sm font-semibold text-[#625568]"><input className="h-5 w-5 accent-[#6f2089]" type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} /> Remember me</label>
                    <button className="hf-btn w-full" disabled={form.processing}>{form.processing ? 'Signing in...' : 'Login'}</button>
                    <div className="text-center text-[11px] font-semibold text-[#a093a5]">SMVS Happy Family Portal</div>
                </form>
            </div>
        </div>
    </>;
}
