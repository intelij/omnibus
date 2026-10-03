'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, Paginated, errMsg } from '@/lib/api';
import { DataTable, Modal } from '@/components/admin';
import { Badge, Button, Input, PageHeader, Spinner, statusTone } from '@/components/ui';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface RouteRow {
  id: number; name: string; origin: string; destination: string;
  distance_km?: number; duration_minutes?: number; base_fare: number;
  status: string; stops_count?: number;
}

export default function RoutesPage() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState({ name: '', origin: '', destination: '', distance_km: '', duration_minutes: '', base_fare: '' });
  const [stops, setStops] = useState<{ name: string; offset_minutes: number }[]>([
    { name: '', offset_minutes: 0 }, { name: '', offset_minutes: 0 },
  ]);

  const { data, isLoading } = useQuery({
    queryKey: ['routes'],
    queryFn: () => api.get<Paginated<RouteRow>>('/routes?per_page=50'),
  });

  const save = useMutation({
    mutationFn: () => api.post('/routes', {
      ...form,
      distance_km: form.distance_km ? +form.distance_km : undefined,
      duration_minutes: form.duration_minutes ? +form.duration_minutes : undefined,
      base_fare: +form.base_fare,
      stops: stops.filter((s) => s.name).map((s, i) => ({
        name: s.name, sequence: i, can_board: i < stops.filter((x) => x.name).length - 1, can_alight: i > 0, offset_minutes: s.offset_minutes,
      })),
    }),
    onSuccess: () => { setOpen(false); qc.invalidateQueries({ queryKey: ['routes'] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Save failed')),
  });

  return (
    <>
      <PageHeader title="Routes" subtitle="Origin → destination with stops and fares">
        <Button onClick={() => { setOpen(true); setError(null); }}><Plus className="h-4 w-4" /> New route</Button>
      </PageHeader>

      {isLoading ? <Spinner /> : (
        <DataTable
          head={['Route', 'Stops', 'Distance', 'Duration', 'Base fare', 'Status']}
          rows={(data?.data ?? []).map((r) => [
            <span key="n" className="font-medium">{r.name}<br /><span className="text-xs font-normal text-ink-faint">{r.origin} → {r.destination}</span></span>,
            r.stops_count ?? '—',
            r.distance_km ? `${r.distance_km} km` : '—',
            r.duration_minutes ? `${Math.floor(r.duration_minutes / 60)}h ${r.duration_minutes % 60}m` : '—',
            `$${r.base_fare}`,
            <Badge key="s" tone={statusTone(r.status)}>{r.status}</Badge>,
          ])}
        />
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="New route">
        <div className="space-y-4">
          <Input label="Route name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <div className="grid grid-cols-2 gap-4">
            <Input label="Origin" value={form.origin} onChange={(e) => setForm({ ...form, origin: e.target.value })} />
            <Input label="Destination" value={form.destination} onChange={(e) => setForm({ ...form, destination: e.target.value })} />
            <Input label="Distance (km)" type="number" value={form.distance_km} onChange={(e) => setForm({ ...form, distance_km: e.target.value })} />
            <Input label="Duration (min)" type="number" value={form.duration_minutes} onChange={(e) => setForm({ ...form, duration_minutes: e.target.value })} />
          </div>
          <Input label="Base fare" type="number" step="0.01" value={form.base_fare} onChange={(e) => setForm({ ...form, base_fare: e.target.value })} />

          <div>
            <div className="mb-2 flex items-center justify-between">
              <span className="text-sm font-medium">Stops (in order)</span>
              <button type="button" className="text-sm text-brand" onClick={() => setStops([...stops, { name: '', offset_minutes: 0 }])}>+ Add stop</button>
            </div>
            <div className="space-y-2">
              {stops.map((s, i) => (
                <div key={i} className="flex items-center gap-2">
                  <span className="w-5 text-xs text-ink-faint">{i + 1}</span>
                  <input className="flex-1 rounded-lg border-0 px-3 py-2 text-sm ring-1 ring-border-strong" placeholder="Stop name" value={s.name}
                    onChange={(e) => setStops(stops.map((x, j) => j === i ? { ...x, name: e.target.value } : x))} />
                  <input className="w-24 rounded-lg border-0 px-3 py-2 text-sm ring-1 ring-border-strong" placeholder="+min" type="number" min={0} value={s.offset_minutes}
                    onChange={(e) => setStops(stops.map((x, j) => j === i ? { ...x, offset_minutes: +e.target.value } : x))} title="Minutes after departure" />
                  {stops.length > 2 && (
                    <button type="button" onClick={() => setStops(stops.filter((_, j) => j !== i))} className="p-1 text-ink-faint hover:text-[var(--danger)]" aria-label="Remove stop">
                      <Trash2 className="h-4 w-4" />
                    </button>
                  )}
                </div>
              ))}
            </div>
          </div>

          {error && <p className="text-sm text-red-600">{error}</p>}
          <Button className="w-full" onClick={() => save.mutate()} disabled={save.isPending}>Create route</Button>
        </div>
      </Modal>
    </>
  );
}
