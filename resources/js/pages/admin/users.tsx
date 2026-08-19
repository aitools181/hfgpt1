import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '../../layouts/app-layout';
import type { PageProps } from '../../types';

type Role = { id: number; name: string; slug: string; module: string };
type UserRow = {
    id: number;
    name: string;
    email: string;
    status: string;
    last_login_at: string | null;
    password_changed_at: string | null;
    can_reset_password: boolean;
    can_manage: boolean;
    roles: Array<{ id: number; name: string; slug: string; zone_id: number | null; center_id: number | null; scope_label: string; is_primary: boolean }>;
};

type Props = {
    users: UserRow[];
    roles: Role[];
    zones: Array<{ id: number; name: string; code: string }>;
    centers: Array<{ id: number; zone_id: number; name: string; code: string }>;
    karyakars: Array<{ id: number; center_id: number; user_id: number | null; full_name: string; karyakar_reference: string }>;
    canManageUsers: boolean;
    canResetPasswords: boolean;
    userSearch: string;
    userListTruncated: boolean;
};

export default function Users({ users, roles, zones, centers, karyakars, canManageUsers, canResetPasswords, userSearch, userListTruncated }: Props) {
    const page = usePage<PageProps>();
    const currentUserId = page.props.auth.user?.id;
    const f = useForm({ name: '', email: '', password: '', status: 'active', role_id: '', zone_id: '', center_id: '', karyakar_id: '' });
    const role = roles.find((r) => String(r.id) === String(f.data.role_id));
    const [karyakarSearch, setKaryakarSearch] = useState('');
    const [eligibleKaryakars, setEligibleKaryakars] = useState(karyakars);
    useEffect(() => {
        if (!f.data.center_id || !['karyakar', 'sanchalak'].includes(role?.slug ?? '')) { setEligibleKaryakars([]); return; }
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            try {
                const params = new URLSearchParams({ center_id: String(f.data.center_id) });
                if (karyakarSearch.trim()) params.set('q', karyakarSearch.trim());
                const response = await fetch(`/admin/users/karyakars/search?${params.toString()}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal });
                if (!response.ok) throw new Error('Karyakar search failed');
                const body = await response.json();
                setEligibleKaryakars(Array.isArray(body.results) ? body.results : []);
            } catch (error: any) { if (error?.name !== 'AbortError') setEligibleKaryakars([]); }
        }, 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [f.data.center_id, role?.slug, karyakarSearch]);

    return <AppLayout title="User & Password Management">
        <Head title="Users" />
        <div className={`grid gap-5 ${canManageUsers ? 'xl:grid-cols-[420px_1fr]' : ''}`}>
            {canManageUsers && <form className="hf-card p-5 space-y-3" onSubmit={(e) => { e.preventDefault(); f.post('/admin/users', { onSuccess: () => f.reset() }); }}>
                <div>
                    <h2 className="font-extrabold">Create User</h2>
                    <p className="mt-1 text-xs text-[#76647e]">User creation remains controlled by the Manage Users permission and organizational scope.</p>
                </div>
                <input className="hf-input" placeholder="Full name" value={f.data.name} onChange={(e) => f.setData('name', e.target.value)} />
                <input className="hf-input" placeholder="Email" type="email" value={f.data.email} onChange={(e) => f.setData('email', e.target.value)} />
                <input className="hf-input" placeholder="Temporary password (12+ chars)" type="password" value={f.data.password} onChange={(e) => f.setData('password', e.target.value)} />
                <select className="hf-input" value={f.data.role_id} onChange={(e) => { f.setData('role_id', e.target.value); f.setData('karyakar_id', ''); }}>
                    <option value="">Select role</option>{roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                </select>
                <select className="hf-input" value={f.data.zone_id} onChange={(e) => f.setData('zone_id', e.target.value)}>
                    <option value="">Zone (if applicable)</option>{zones.map((z) => <option key={z.id} value={z.id}>{z.code} - {z.name}</option>)}
                </select>
                <select className="hf-input" value={f.data.center_id} onChange={(e) => { f.setData('center_id', e.target.value); f.setData('karyakar_id', ''); }}>
                    <option value="">Center (if applicable)</option>{centers.map((c) => <option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}
                </select>
                {['karyakar', 'sanchalak'].includes(role?.slug ?? '') && <div>
                    <label className="hf-label">Find Approved Karyakar</label>
                    <input className="hf-input mb-2" value={karyakarSearch} onChange={(e) => setKaryakarSearch(e.target.value)} disabled={!f.data.center_id} placeholder={f.data.center_id ? 'Search name or Karyakar reference' : 'Select Center first'} />
                    <label className="hf-label">Link Approved Karyakar</label>
                    <select className="hf-input" value={f.data.karyakar_id} onChange={(e) => f.setData('karyakar_id', e.target.value)}>
                        <option value="">Select Karyakar</option>{eligibleKaryakars.map((k) => <option key={k.id} value={k.id}>{k.karyakar_reference} - {k.full_name}</option>)}
                    </select>
                    <p className="text-xs text-[#76647e] mt-1">Search returns a bounded list; Karyakar links enable own field assignments and Sanchalak links enable Bal Pravruti reporting.</p>
                </div>}
                {Object.values(f.errors).length > 0 && <div className="rounded-xl bg-red-50 p-3 text-xs text-red-700">{Object.values(f.errors)[0]}</div>}
                <button className="hf-btn" disabled={f.processing}>Create User</button>
            </form>}

            <div className="hf-card p-5 overflow-x-auto">
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="font-extrabold">Portal Users</h2>
                        <p className="mt-1 text-xs text-[#76647e]">Only users inside your permitted organizational scope are listed.</p>
                    </div>
                    {canResetPasswords && <span className="hf-badge">Password reset enabled</span>}
                </div>
                <form method="get" action="/admin/users" className="mb-4 flex flex-wrap gap-2">
                    <input className="hf-input max-w-md" name="search" defaultValue={userSearch} placeholder="Search user name or email" />
                    <button className="hf-btn" type="submit">Search</button>
                    {userSearch && <a className="hf-btn hf-btn-secondary" href="/admin/users">Clear</a>}
                </form>
                {userListTruncated && <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900">Large user list detected. Showing a safe bounded result set; use search to find any specific user.</div>}
                <div className="hf-table-scroll"><table className="hf-table hf-mobile-table">
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Scope</th><th>Status</th><th>Last password reset</th>{canResetPasswords && <th>Security action</th>}</tr></thead>
                    <tbody>{users.map((u) => {
                        const r = u.roles.find((item) => item.is_primary) ?? u.roles[0];
                        return <tr key={u.id}>
                            <td><div className="font-semibold">{u.name}</div>{u.id === currentUserId && <div className="text-xs text-[#7b5f87]">Current account</div>}</td>
                            <td>{u.email}</td>
                            <td>{r?.name ?? '-'}</td>
                            <td>{r?.scope_label ?? 'Organization - SPK'}</td>
                            <td><span className="hf-badge">{u.status}</span></td>
                            <td>{u.password_changed_at ? new Date(u.password_changed_at).toLocaleString() : 'Not reset yet'}</td>
                            {canResetPasswords && <td className="min-w-[290px]">
                                {u.can_reset_password
                                    ? <PasswordResetForm user={u} isSelf={u.id === currentUserId} />
                                    : <span className="text-xs text-[#8a748f]">Protected / outside reset authority</span>}
                            </td>}
                        </tr>;
                    })}</tbody>
                </table></div>
            </div>
        </div>
    </AppLayout>;
}

function PasswordResetForm({ user, isSelf }: { user: UserRow; isSelf: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ password: '', password_confirmation: '', reason: '' });

    if (!open) {
        return <button type="button" className="hf-btn" onClick={() => setOpen(true)}>Reset password</button>;
    }

    return <form className="space-y-2 rounded-xl border border-[#eadff0] bg-[#fcf9fd] p-3" onSubmit={(e) => {
        e.preventDefault();
        form.put(`/admin/users/${user.id}/password`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    }}>
        <div className="text-xs font-extrabold text-[#4d1a5d]">Reset {user.name}</div>
        <input className="hf-input" type="password" autoComplete="new-password" placeholder="New password (12+ chars)" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
        <input className="hf-input" type="password" autoComplete="new-password" placeholder="Confirm new password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
        <input className="hf-input" placeholder="Reason / note (optional)" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
        {form.errors.password && <div className="text-xs font-semibold text-red-700">{form.errors.password}</div>}
        {form.errors.password_confirmation && <div className="text-xs font-semibold text-red-700">{form.errors.password_confirmation}</div>}
        {isSelf && <div className="text-xs text-amber-700">Resetting your own password will sign you out immediately.</div>}
        <div className="flex flex-wrap gap-2">
            <button className="hf-btn" disabled={form.processing}>Confirm reset</button>
            <button type="button" className="rounded-lg border border-[#d9c6df] px-3 py-2 text-xs font-semibold" onClick={() => { form.reset(); setOpen(false); }}>Cancel</button>
        </div>
    </form>;
}
