'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, clearSession, getToken, getUser, Paginated, UserProfile, errMsg } from '@/lib/api';
import { DataTable, Modal } from '@/components/admin';
import { Badge, Button, Input, PageHeader, Select, Spinner } from '@/components/ui';
import { Plus } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';

interface TenantRow { id: number; slug: string; legal_name: string; trading_name: string; plan: string; status: string; currency: string }

export default function PlatformPage() {
  const router = useRouter();
  const qc = useQueryClient();
  const [user] = useState<UserProfile | null>(() => getUser());
  const [ready, setReady] = useState(false);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState({
    legal_name: '', trading_name: '', slug: '', email: '', phone: '',
    currency: 'USD', timezone: 'Africa/Harare', plan: 'starter',
    owner_name: '', owner_email: '', owner_password: '',
  });

  useEffect(() => {
    if (!getToken() || !user?.is_platform_user) { router.replace('/admin/login'); return; }
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setReady(true);
  }, [router, user]);

  const { data, isLoading } = useQuery({ queryKey: ['platform-tenants'], queryFn: () => api.get<Paginated<TenantRow>>('/platform/tenants?per_page=50'), enabled: ready });
  const { data: overview } = useQuery({ queryKey: ['platform-overview'], queryFn: () => api.get<{ tenants: { total: number; active: number }; bookings_30d: number }>('/platform/reports/overview'), enabled: ready });

  const create = useMutation({
    mutationFn: () => api.post('/platform/tenants', {
      ...form,
      owner: { name: form.owner_name, email: form.owner_email, password: form.owner_password },
    }),
    onSuccess: () => { setOpen(false); qc.invalidateQueries({ queryKey: ['platform-tenants'] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Create failed')),
  });

  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => api.patch(`/platform/tenants/${id}/status`, { status }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['platform-tenants'] }),
  });

  if (!ready) return null;

  return (
    <div className="min-h-screen bg-slate-50 p-6">
      <div className="mx-auto max-w-5xl">
        <div className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Platform administration</h1>
            <p className="text-sm text-slate-500">{user?.name} · platform_super_admin</p>
          </div>
          <Button variant="ghost" onClick={() => { clearSession(); router.push('/admin/login'); }}>Sign out</Button>
        </div>

        {overview && (
          <div className="mb-6 grid gap-4 sm:grid-cols-3">
            <div className="rounded-xl bg-white p-4 ring-1 ring-slate-200"><p className="text-2xl font-bold">{overview.tenants.total}</p><p className="text-xs text-slate-500">Tenants</p></div>
            <div className="rounded-xl bg-white p-4 ring-1 ring-slate-200"><p className="text-2xl font-bold">{overview.tenants.active}</p><p className="text-xs text-slate-500">Active</p></div>
            <div className="rounded-xl bg-white p-4 ring-1 ring-slate-200"><p className="text-2xl font-bold">{overview.bookings_30d}</p><p className="text-xs text-slate-500">Bookings (30d)</p></div>
          </div>
        )}

        <PageHeader title="Tenants">
          <Button onClick={() => { setOpen(true); setError(null); }}><Plus className="h-4 w-4" /> Onboard operator</Button>
        </PageHeader>

        {isLoading ? <Spinner /> : (
          <DataTable
            head={['Operator', 'Slug', 'Plan', 'Currency', 'Status', 'Actions']}
            rows={(data?.data ?? []).map((t) => [
              <span key="n" className="font-medium">{t.trading_name}<br /><span className="text-xs font-normal text-slate-400">{t.legal_name}</span></span>,
              <code key="s" className="text-xs">{t.slug}</code>,
              t.plan,
              t.currency,
              <Badge key="t" tone={t.status === 'active' ? 'green' : t.status === 'suspended' ? 'red' : 'gray'}>{t.status}</Badge>,
              <div key="a" className="flex gap-2">
                {t.status !== 'active' && <button className="text-sm text-teal-700 hover:underline" onClick={() => setStatus.mutate({ id: t.id, status: 'active' })}>Activate</button>}
                {t.status === 'active' && <button className="text-sm text-red-600 hover:underline" onClick={() => setStatus.mutate({ id: t.id, status: 'suspended' })}>Suspend</button>}
              </div>,
            ])}
          />
        )}
      </div>

      <Modal open={open} onClose={() => setOpen(false)} title="Onboard new operator">
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <Input label="Legal name" value={form.legal_name} onChange={(e) => setForm({ ...form, legal_name: e.target.value })} />
            <Input label="Trading name" value={form.trading_name} onChange={(e) => setForm({ ...form, trading_name: e.target.value })} />
            <Input label="Slug" value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} />
            <Input label="Billing email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            <Input label="Currency" value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value.toUpperCase() })} />
            <Select label="Plan" value={form.plan} onChange={(e) => setForm({ ...form, plan: e.target.value })}>
              {['starter', 'professional', 'enterprise'].map((p) => <option key={p} value={p}>{p}</option>)}
            </Select>
          </div>
          <hr className="border-slate-100" />
          <h3 className="text-sm font-semibold">Owner account</h3>
          <Input label="Owner name" value={form.owner_name} onChange={(e) => setForm({ ...form, owner_name: e.target.value })} />
          <Input label="Owner email" type="email" value={form.owner_email} onChange={(e) => setForm({ ...form, owner_email: e.target.value })} />
          <Input label="Temporary password" value={form.owner_password} onChange={(e) => setForm({ ...form, owner_password: e.target.value })} />
          {error && <p className="text-sm text-red-600">{error}</p>}
          <Button className="w-full" onClick={() => create.mutate()} disabled={create.isPending || !form.slug || !form.owner_email}>Create tenant</Button>
        </div>
      </Modal>
    </div>
  );
}
