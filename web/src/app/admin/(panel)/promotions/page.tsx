'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, Paginated, errMsg } from '@/lib/api';
import { DataTable, Modal } from '@/components/admin';
import { Badge, Button, Input, PageHeader, Select, Spinner, statusTone } from '@/components/ui';
import { Plus } from 'lucide-react';
import { useState } from 'react';

interface Promo { id: number; code: string; name: string; type: string; value: number; status: string; redemptions_count?: number; usage_limit?: number; ends_at?: string }

export default function PromotionsPage() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState({ code: '', name: '', type: 'percent', value: '', usage_limit: '', per_customer_limit: '', ends_at: '', min_spend: '', max_discount: '' });

  const { data, isLoading } = useQuery({ queryKey: ['promotions'], queryFn: () => api.get<Paginated<Promo>>('/promotions?per_page=50') });

  const save = useMutation({
    mutationFn: () => api.post('/promotions', {
      code: form.code, name: form.name, type: form.type, value: +form.value,
      usage_limit: form.usage_limit ? +form.usage_limit : undefined,
      per_customer_limit: form.per_customer_limit ? +form.per_customer_limit : undefined,
      ends_at: form.ends_at || undefined,
      min_spend: form.min_spend ? +form.min_spend : undefined,
      max_discount: form.max_discount ? +form.max_discount : undefined,
      applies_to: 'all',
    }),
    onSuccess: () => { setOpen(false); qc.invalidateQueries({ queryKey: ['promotions'] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Save failed')),
  });

  const pause = useMutation({
    mutationFn: (p: Promo) => api.patch(`/promotions/${p.id}`, { status: p.status === 'active' ? 'paused' : 'active' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['promotions'] }),
  });

  return (
    <>
      <PageHeader title="Promotions" subtitle="Discount codes and campaigns">
        <Button onClick={() => { setOpen(true); setError(null); }}><Plus className="h-4 w-4" /> New promotion</Button>
      </PageHeader>

      {isLoading ? <Spinner /> : (
        <DataTable
          head={['Code', 'Name', 'Discount', 'Redemptions', 'Valid until', 'Status', 'Actions']}
          rows={(data?.data ?? []).map((p) => [
            <span key="c" className="font-mono font-semibold">{p.code}</span>,
            p.name,
            p.type === 'percent' ? `${p.value}%` : `$${p.value}`,
            `${p.redemptions_count ?? 0}${p.usage_limit ? ` / ${p.usage_limit}` : ''}`,
            p.ends_at ? new Date(p.ends_at).toLocaleDateString() : '∞',
            <Badge key="s" tone={statusTone(p.status)}>{p.status}</Badge>,
            <button key="a" className="text-sm text-brand hover:underline" onClick={() => pause.mutate(p)}>
              {p.status === 'active' ? 'Pause' : 'Activate'}
            </button>,
          ])}
        />
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="New promotion">
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <Input label="Code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} placeholder="LAUNCH10" />
            <Input label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            <Select label="Type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
              <option value="percent">Percent off</option>
              <option value="fixed">Fixed amount off</option>
            </Select>
            <Input label={form.type === 'percent' ? 'Percent' : 'Amount'} type="number" step="0.01" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} />
            <Input label="Usage limit (optional)" type="number" value={form.usage_limit} onChange={(e) => setForm({ ...form, usage_limit: e.target.value })} />
            <Input label="Per-customer limit" type="number" value={form.per_customer_limit} onChange={(e) => setForm({ ...form, per_customer_limit: e.target.value })} />
            <Input label="Min spend (optional)" type="number" value={form.min_spend} onChange={(e) => setForm({ ...form, min_spend: e.target.value })} />
            <Input label="Max discount cap (optional)" type="number" value={form.max_discount} onChange={(e) => setForm({ ...form, max_discount: e.target.value })} />
          </div>
          <Input label="Expires (optional)" type="date" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} />
          {error && <p className="text-sm text-red-600">{error}</p>}
          <Button className="w-full" onClick={() => save.mutate()} disabled={save.isPending || !form.code || !form.value}>Create promotion</Button>
        </div>
      </Modal>
    </>
  );
}
