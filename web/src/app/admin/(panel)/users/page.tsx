'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, Paginated, errMsg } from '@/lib/api';
import { DataTable, Modal } from '@/components/admin';
import { Badge, Button, Input, PageHeader, Select, Spinner } from '@/components/ui';
import { Plus } from 'lucide-react';
import { useState } from 'react';

interface UserRow { id: number; name: string; email: string; status: string; roles: { name: string }[] }

const ROLES = [
  { value: 'tenant_admin', label: 'Admin' },
  { value: 'booking_agent', label: 'Booking agent' },
  { value: 'finance_officer', label: 'Finance officer' },
  { value: 'support_agent', label: 'Support agent' },
];

export default function UsersPage() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', role: 'booking_agent' });

  const { data, isLoading } = useQuery({ queryKey: ['users'], queryFn: () => api.get<Paginated<UserRow>>('/users?per_page=50') });

  const save = useMutation({
    mutationFn: () => api.post('/users', form),
    onSuccess: () => { setOpen(false); qc.invalidateQueries({ queryKey: ['users'] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Save failed')),
  });

  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => api.post(`/users/${id}/status`, { status }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
  });

  return (
    <>
      <PageHeader title="Team" subtitle="Staff accounts and roles">
        <Button onClick={() => { setOpen(true); setError(null); }}><Plus className="h-4 w-4" /> Invite staff</Button>
      </PageHeader>

      {isLoading ? <Spinner /> : (
        <DataTable
          head={['Name', 'Email', 'Role', 'Status', 'Actions']}
          rows={(data?.data ?? []).map((u) => [
            <span key="n" className="font-medium">{u.name}</span>,
            u.email,
            <Badge key="r" tone="blue">{u.roles[0]?.name.replace('_', ' ') ?? '—'}</Badge>,
            <Badge key="s" tone={u.status === 'active' ? 'green' : 'red'}>{u.status}</Badge>,
            <button key="a" className="text-sm text-brand hover:underline" onClick={() => setStatus.mutate({ id: u.id, status: u.status === 'active' ? 'disabled' : 'active' })}>
              {u.status === 'active' ? 'Disable' : 'Enable'}
            </button>,
          ])}
        />
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="Invite staff member">
        <div className="space-y-4">
          <Input label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <Input label="Email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <Input label="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          <Input label="Temporary password" type="text" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
          <Select label="Role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
            {ROLES.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
          </Select>
          {error && <p className="text-sm text-red-600">{error}</p>}
          <Button className="w-full" onClick={() => save.mutate()} disabled={save.isPending || !form.email || !form.password}>Create account</Button>
        </div>
      </Modal>
    </>
  );
}
