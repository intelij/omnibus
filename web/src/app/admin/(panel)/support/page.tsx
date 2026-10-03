'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, Paginated } from '@/lib/api';
import { DataTable, Modal } from '@/components/admin';
import { Badge, Button, Input, PageHeader, Select, Spinner, statusTone } from '@/components/ui';
import { Plus } from 'lucide-react';
import { useState } from 'react';

interface Ticket { id: number; subject: string; status: string; priority: string; customer_name?: string; created_at: string; assignee?: { name: string } }

export default function SupportPage() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [selected, setSelected] = useState<Ticket | null>(null);
  const [note, setNote] = useState('');
  const [form, setForm] = useState({ subject: '', customer_name: '', customer_contact: '', description: '', priority: 'normal' });

  const { data, isLoading } = useQuery({ queryKey: ['tickets'], queryFn: () => api.get<Paginated<Ticket>>('/support-tickets?per_page=50') });
  const { data: detail } = useQuery({
    queryKey: ['ticket', selected?.id],
    queryFn: () => api.get<{ description?: string; status: string; notes?: { id: number; body: string; created_at: string }[] }>(`/support-tickets/${selected!.id}`),
    enabled: !!selected,
  });

  const create = useMutation({
    mutationFn: () => api.post('/support-tickets', form),
    onSuccess: () => { setOpen(false); qc.invalidateQueries({ queryKey: ['tickets'] }); },
  });
  const addNote = useMutation({
    mutationFn: () => api.post(`/support-tickets/${selected!.id}/notes`, { body: note }),
    onSuccess: () => { setNote(''); qc.invalidateQueries({ queryKey: ['ticket', selected?.id] }); },
  });
  const update = useMutation({
    mutationFn: (patch: Record<string, string>) => api.patch(`/support-tickets/${selected!.id}`, patch),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['tickets'] }); qc.invalidateQueries({ queryKey: ['ticket', selected?.id] }); },
  });

  return (
    <>
      <PageHeader title="Support" subtitle="Passenger issues and enquiries">
        <Button onClick={() => setOpen(true)}><Plus className="h-4 w-4" /> New ticket</Button>
      </PageHeader>

      {isLoading ? <Spinner /> : (
        <DataTable
          head={['Subject', 'Customer', 'Priority', 'Status', 'Assignee', 'Opened']}
          rows={(data?.data ?? []).map((t) => [
            <button key="s" className="font-medium text-teal-700 hover:underline" onClick={() => setSelected(t)}>{t.subject}</button>,
            t.customer_name ?? '—',
            <Badge key="p" tone={t.priority === 'urgent' || t.priority === 'high' ? 'red' : 'gray'}>{t.priority}</Badge>,
            <Badge key="t" tone={statusTone(t.status)}>{t.status}</Badge>,
            t.assignee?.name ?? '—',
            new Date(t.created_at).toLocaleDateString(),
          ])}
        />
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="New support ticket">
        <div className="space-y-4">
          <Input label="Subject" value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} />
          <div className="grid grid-cols-2 gap-4">
            <Input label="Customer name" value={form.customer_name} onChange={(e) => setForm({ ...form, customer_name: e.target.value })} />
            <Input label="Contact" value={form.customer_contact} onChange={(e) => setForm({ ...form, customer_contact: e.target.value })} />
          </div>
          <Select label="Priority" value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
            {['low', 'normal', 'high', 'urgent'].map((p) => <option key={p} value={p}>{p}</option>)}
          </Select>
          <label className="block"><span className="mb-1 block text-sm font-medium">Description</span>
            <textarea className="w-full rounded-lg border-0 px-3 py-2 text-sm ring-1 ring-slate-300" rows={4} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></label>
          <Button className="w-full" onClick={() => create.mutate()} disabled={!form.subject}>Create ticket</Button>
        </div>
      </Modal>

      <Modal open={!!selected} onClose={() => setSelected(null)} title={selected?.subject ?? ''}>
        {detail && (
          <div className="space-y-4 text-sm">
            <p className="text-slate-600">{detail.description ?? 'No description.'}</p>
            <div className="flex gap-2">
              {['open', 'pending_customer', 'resolved', 'closed'].map((s) => (
                <button key={s} onClick={() => update.mutate({ status: s })}
                  className={`rounded-lg px-2.5 py-1 text-xs font-medium ${detail.status === s ? 'bg-teal-700 text-white' : 'bg-slate-100 text-slate-600'}`}>
                  {s.replace('_', ' ')}
                </button>
              ))}
            </div>
            <div className="border-t border-slate-100 pt-3">
              <h3 className="mb-2 font-semibold">Notes</h3>
              <ul className="space-y-2">
                {(detail.notes ?? []).map((n) => (
                  <li key={n.id} className="rounded-lg bg-slate-50 p-2.5"><p>{n.body}</p><p className="mt-1 text-xs text-slate-400">{new Date(n.created_at).toLocaleString()}</p></li>
                ))}
              </ul>
              <div className="mt-3 flex gap-2">
                <input className="flex-1 rounded-lg border-0 px-3 py-2 text-sm ring-1 ring-slate-300" placeholder="Add note…" value={note} onChange={(e) => setNote(e.target.value)} />
                <Button variant="secondary" onClick={() => addNote.mutate()} disabled={!note}>Add</Button>
              </div>
            </div>
          </div>
        )}
      </Modal>
    </>
  );
}
