'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, Paginated, errMsg } from '@/lib/api';
import { DataTable, Modal } from '@/components/admin';
import { Badge, Button, Input, PageHeader, Select, Spinner, statusTone } from '@/components/ui';
import { Plus } from 'lucide-react';
import { useState } from 'react';

interface BusRow {
  id: number;
  registration_number: string;
  fleet_number?: string;
  bus_type: string;
  capacity: number;
  status: string;
  seat_layout_id?: number | null;
  seat_layout?: { name: string };
  amenities?: string[];
}

export default function BusesPage() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<BusRow | null>(null);
  const [form, setForm] = useState({ registration_number: '', fleet_number: '', bus_type: 'coach', seat_layout_id: '', status: 'active', amenities: '' });
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['buses'],
    queryFn: () => api.get<Paginated<BusRow>>('/buses?per_page=50'),
  });

  const { data: layouts } = useQuery({
    queryKey: ['layouts'],
    queryFn: () => api.get<Paginated<{ id: number; name: string }>>('/seat-layouts?per_page=100'),
  });

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        ...form,
        seat_layout_id: form.seat_layout_id ? +form.seat_layout_id : null,
        amenities: form.amenities ? form.amenities.split(',').map((a) => a.trim()) : [],
      };
      return editing ? api.patch(`/buses/${editing.id}`, payload) : api.post('/buses', payload);
    },
    onSuccess: () => { setOpen(false); qc.invalidateQueries({ queryKey: ['buses'] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Save failed')),
  });

  const retire = useMutation({
    mutationFn: (id: number) => api.patch(`/buses/${id}`, { status: 'retired' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['buses'] }),
  });

  const openForm = (bus?: BusRow) => {
    setEditing(bus ?? null);
    setForm(bus
      ? { registration_number: bus.registration_number, fleet_number: bus.fleet_number ?? '', bus_type: bus.bus_type, seat_layout_id: String(bus.seat_layout_id ?? ''), status: bus.status, amenities: (bus.amenities ?? []).join(', ') }
      : { registration_number: '', fleet_number: '', bus_type: 'coach', seat_layout_id: '', status: 'active', amenities: '' });
    setError(null);
    setOpen(true);
  };

  return (
    <>
      <PageHeader title="Fleet" subtitle="Buses and their seat layouts">
        <Button onClick={() => openForm()}><Plus className="h-4 w-4" /> Add bus</Button>
      </PageHeader>

      {isLoading ? <Spinner /> : (
        <DataTable
          head={['Registration', 'Fleet #', 'Type', 'Layout', 'Capacity', 'Status', 'Actions']}
          rows={(data?.data ?? []).map((b) => [
            <span key="r" className="font-medium">{b.registration_number}</span>,
            b.fleet_number ?? '—',
            b.bus_type.replace('_', ' '),
            b.seat_layout?.name ?? '—',
            b.capacity,
            <Badge key="s" tone={statusTone(b.status)}>{b.status}</Badge>,
            <div key="a" className="flex gap-3">
              <button className="text-sm text-brand hover:underline" onClick={() => openForm(b)}>Edit</button>
              {b.status !== 'retired' && <button className="text-sm text-red-600 hover:underline" onClick={() => retire.mutate(b.id)}>Retire</button>}
            </div>,
          ])}
        />
      )}

      <Modal open={open} onClose={() => setOpen(false)} title={editing ? `Edit ${editing.registration_number}` : 'Add bus'}>
        <div className="space-y-4">
          <Input label="Registration number" value={form.registration_number} onChange={(e) => setForm({ ...form, registration_number: e.target.value })} />
          <div className="grid grid-cols-2 gap-4">
            <Input label="Fleet number" value={form.fleet_number} onChange={(e) => setForm({ ...form, fleet_number: e.target.value })} />
            <Select label="Type" value={form.bus_type} onChange={(e) => setForm({ ...form, bus_type: e.target.value })}>
              {['coach', 'minibus', 'double_decker', 'vip_coach'].map((t) => <option key={t} value={t}>{t.replace('_', ' ')}</option>)}
            </Select>
          </div>
          <Select label="Seat layout" value={form.seat_layout_id} onChange={(e) => setForm({ ...form, seat_layout_id: e.target.value })}>
            <option value="">No layout</option>
            {(layouts?.data ?? []).map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
          </Select>
          <Input label="Amenities (comma separated)" value={form.amenities} onChange={(e) => setForm({ ...form, amenities: e.target.value })} placeholder="wifi, usb_charging, air_conditioning" />
          <Select label="Status" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
            {['active', 'inactive', 'maintenance', 'retired'].map((s) => <option key={s} value={s}>{s}</option>)}
          </Select>
          {error && <p className="text-sm text-red-600">{error}</p>}
          <Button className="w-full" onClick={() => save.mutate()} disabled={save.isPending || !form.registration_number}>
            {save.isPending ? 'Saving…' : 'Save bus'}
          </Button>
        </div>
      </Modal>
    </>
  );
}
