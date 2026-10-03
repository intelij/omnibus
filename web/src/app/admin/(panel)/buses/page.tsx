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
  seat_layout?: { name: string; seats_count?: number; rows?: number; cols?: number };
  amenities?: string[];
}

interface SeatConfig { rows: number; left: number; right: number; rear: number }

const DEFAULT_CFG: SeatConfig = { rows: 11, left: 2, right: 2, rear: 5 };

/** Authoritative preview formula — server re-derives on save. */
const capacityOf = (c: SeatConfig) => c.rows * (c.left + c.right) + c.rear;

export default function BusesPage() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<BusRow | null>(null);
  const [form, setForm] = useState({ registration_number: '', fleet_number: '', bus_type: 'coach', seat_layout_id: '', status: 'active', amenities: '' });
  const [seatMode, setSeatMode] = useState<'existing' | 'generate'>('generate');
  const [cfg, setCfg] = useState<SeatConfig>(DEFAULT_CFG);
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['buses'],
    queryFn: () => api.get<Paginated<BusRow>>('/buses?per_page=50'),
  });

  const { data: layouts } = useQuery({
    queryKey: ['layouts'],
    queryFn: () => api.get<Paginated<{ id: number; name: string; seats_count?: number }>>('/seat-layouts?per_page=100'),
  });

  const save = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = {
        registration_number: form.registration_number,
        fleet_number: form.fleet_number,
        bus_type: form.bus_type,
        status: form.status,
        amenities: form.amenities ? form.amenities.split(',').map((a) => a.trim()) : [],
      };
      if (seatMode === 'generate') {
        payload.seating_config = {
          rows: cfg.rows, left_seats: cfg.left, right_seats: cfg.right, rear_row_seats: cfg.rear,
        };
      } else {
        payload.seat_layout_id = form.seat_layout_id ? +form.seat_layout_id : null;
      }
      return editing ? api.patch(`/buses/${editing.id}`, payload) : api.post('/buses', payload);
    },
    onSuccess: () => { setOpen(false); qc.invalidateQueries({ queryKey: ['buses', 'layouts'] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Save failed')),
  });

  const retire = useMutation({
    mutationFn: (id: number) => api.patch(`/buses/${id}`, { status: 'retired' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['buses'] }),
  });

  const openForm = (bus?: BusRow) => {
    setEditing(bus ?? null);
    setSeatMode(bus ? 'existing' : 'generate');
    setForm(bus
      ? { registration_number: bus.registration_number, fleet_number: bus.fleet_number ?? '', bus_type: bus.bus_type, seat_layout_id: String(bus.seat_layout_id ?? ''), status: bus.status, amenities: (bus.amenities ?? []).join(', ') }
      : { registration_number: '', fleet_number: '', bus_type: 'coach', seat_layout_id: '', status: 'active', amenities: '' });
    setCfg(DEFAULT_CFG);
    setError(null);
    setOpen(true);
  };

  const capacity = capacityOf(cfg);

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
            b.seat_layout
              ? <span key="l" className="text-sm">{b.seat_layout.name}<span className="ml-1 text-xs text-ink-faint">({b.seat_layout.seats_count ?? '?'} seats)</span></span>
              : <span key="l" className="text-sm text-red-600">No seats configured — edit to configure</span>,
            <span key="c" className={b.capacity > 0 ? '' : 'text-red-600 font-semibold'}>{b.capacity || 'missing'}</span>,
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

          {/* ── Seating configuration ── */}
          <div className="rounded-lg bg-surface-muted p-4">
            <div className="mb-3 flex items-center justify-between">
              <h3 className="text-sm font-semibold">Seating configuration</h3>
              <div className="flex gap-2 text-xs">
                <button type="button" onClick={() => setSeatMode('generate')}
                  className={`rounded-full px-3 py-1 font-medium ${seatMode === 'generate' ? 'bg-brand text-white' : 'bg-surface text-ink-muted ring-1 ring-border'}`}>
                  Generate layout
                </button>
                <button type="button" onClick={() => setSeatMode('existing')}
                  className={`rounded-full px-3 py-1 font-medium ${seatMode === 'existing' ? 'bg-brand text-white' : 'bg-surface text-ink-muted ring-1 ring-border'}`}>
                  Use existing
                </button>
              </div>
            </div>

            {seatMode === 'generate' ? (
              <>
                <p className="mb-3 text-xs text-ink-muted">
                  {editing
                    ? 'Generates a new layout version — existing trips keep their original seat map.'
                    : 'The layout is generated automatically when the bus is saved.'}
                </p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  <Input label="Standard rows" type="number" min={1} max={40} value={cfg.rows}
                    onChange={(e) => setCfg({ ...cfg, rows: Math.max(1, +e.target.value || 1) })} />
                  <Input label="Left of aisle" type="number" min={0} max={4} value={cfg.left}
                    onChange={(e) => setCfg({ ...cfg, left: Math.max(0, +e.target.value || 0) })} />
                  <Input label="Right of aisle" type="number" min={0} max={4} value={cfg.right}
                    onChange={(e) => setCfg({ ...cfg, right: Math.max(0, +e.target.value || 0) })} />
                  <Input label="Rear row" type="number" min={0} max={10} value={cfg.rear}
                    onChange={(e) => setCfg({ ...cfg, rear: Math.max(0, +e.target.value || 0) })} />
                </div>
                <p className="mt-1 text-xs text-ink-muted">
                  e.g. 2 left + 2 right makes a 2+2 coach; a rear row of 5 fits the common ZW layout.
                </p>
                <div className="mt-3 rounded-lg bg-surface px-3 py-2 ring-1 ring-border">
                  <p className="text-sm">
                    <span className="font-semibold">Calculated capacity: </span>
                    <span className="font-mono font-bold text-brand">{capacity} seats</span>
                  </p>
                  <p className="mt-0.5 text-xs text-ink-faint font-mono">
                    {cfg.rows} × ({cfg.left} + {cfg.right}) + {cfg.rear} = {capacity}
                  </p>
                </div>
              </>
            ) : (
              <Select label="Seat layout" value={form.seat_layout_id} onChange={(e) => setForm({ ...form, seat_layout_id: e.target.value })}>
                <option value="">Choose a published layout…</option>
                {(layouts?.data ?? []).map((l) => <option key={l.id} value={l.id}>{l.name}{l.seats_count != null ? ` (${l.seats_count} seats)` : ''}</option>)}
              </Select>
            )}
          </div>

          <Input label="Amenities (comma separated)" value={form.amenities} onChange={(e) => setForm({ ...form, amenities: e.target.value })} placeholder="wifi, usb_charging, air_conditioning" />
          <Select label="Status" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
            {['active', 'inactive', 'maintenance', 'retired'].map((s) => <option key={s} value={s}>{s}</option>)}
          </Select>
          {error && <p className="text-sm text-red-600">{error}</p>}
          <Button className="w-full" onClick={() => save.mutate()}
            disabled={save.isPending || !form.registration_number || (seatMode === 'existing' && !form.seat_layout_id && !editing)}>
            {save.isPending ? 'Saving…' : 'Save bus'}
          </Button>
        </div>
      </Modal>
    </>
  );
}
