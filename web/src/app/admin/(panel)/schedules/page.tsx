'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, Paginated, errMsg } from '@/lib/api';
import { DataTable, Modal } from '@/components/admin';
import { Badge, Button, Input, PageHeader, Select, Spinner, statusTone } from '@/components/ui';
import { CalendarPlus, Plus } from 'lucide-react';
import { useState } from 'react';

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

interface ScheduleRow {
  id: number; name?: string; departure_time: string; days_of_week: number[];
  status: string; route?: { name: string }; bus?: { registration_number: string };
  start_date: string; end_date?: string;
}

export default function SchedulesPage() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [genDays, setGenDays] = useState(30);
  const [form, setForm] = useState({ route_id: '', bus_id: '', name: '', departure_time: '07:30', days: [] as number[], start_date: '', end_date: '', fare_override: '' });

  const { data, isLoading } = useQuery({ queryKey: ['schedules'], queryFn: () => api.get<Paginated<ScheduleRow>>('/schedules?per_page=50') });
  const { data: routes } = useQuery({ queryKey: ['routes-all'], queryFn: () => api.get<Paginated<{ id: number; name: string }>>('/routes?per_page=100') });
  const { data: buses } = useQuery({ queryKey: ['buses-all'], queryFn: () => api.get<Paginated<{ id: number; registration_number: string }>>('/buses?per_page=100') });

  const save = useMutation({
    mutationFn: () => api.post('/schedules', {
      route_id: +form.route_id, bus_id: +form.bus_id,
      name: form.name || undefined, departure_time: form.departure_time,
      days_of_week: form.days, start_date: form.start_date,
      end_date: form.end_date || undefined,
      fare_override: form.fare_override ? +form.fare_override : undefined,
      generate_days: genDays,
    }),
    onSuccess: () => { setOpen(false); qc.invalidateQueries({ queryKey: ['schedules'] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Save failed')),
  });

  const generate = useMutation({
    mutationFn: (id: number) => api.post<{ created: number }>(`/schedules/${id}/generate-trips`, { days: 30 }),
    onSuccess: (r: { created: number }) => alert(`${r.created} new trips created`),
  });

  const toggleDay = (d: number) => setForm({ ...form, days: form.days.includes(d) ? form.days.filter((x) => x !== d) : [...form.days, d].sort() });

  return (
    <>
      <PageHeader title="Schedules" subtitle="Recurring departure patterns that generate trips">
        <Button onClick={() => { setOpen(true); setError(null); }}><Plus className="h-4 w-4" /> New schedule</Button>
      </PageHeader>

      {isLoading ? <Spinner /> : (
        <DataTable
          head={['Name', 'Route', 'Bus', 'Departs', 'Runs on', 'Status', 'Actions']}
          rows={(data?.data ?? []).map((s) => [
            <span key="n" className="font-medium">{s.name ?? `Schedule #${s.id}`}</span>,
            s.route?.name ?? '—',
            s.bus?.registration_number ?? '—',
            s.departure_time,
            <span key="d" className="text-xs">{s.days_of_week?.map((d) => DAYS[d - 1]).join(' ')}</span>,
            <Badge key="s" tone={statusTone(s.status)}>{s.status}</Badge>,
            <button key="a" className="flex items-center gap-1 text-sm text-teal-700 hover:underline" onClick={() => generate.mutate(s.id)}>
              <CalendarPlus className="h-3.5 w-3.5" /> Generate trips
            </button>,
          ])}
        />
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="New schedule">
        <div className="space-y-4">
          <Input label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="e.g. Morning Express" />
          <div className="grid grid-cols-2 gap-4">
            <Select label="Route" value={form.route_id} onChange={(e) => setForm({ ...form, route_id: e.target.value })}>
              <option value="">Choose…</option>
              {(routes?.data ?? []).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </Select>
            <Select label="Bus" value={form.bus_id} onChange={(e) => setForm({ ...form, bus_id: e.target.value })}>
              <option value="">Choose…</option>
              {(buses?.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.registration_number}</option>)}
            </Select>
            <Input label="Departure time" type="time" value={form.departure_time} onChange={(e) => setForm({ ...form, departure_time: e.target.value })} />
            <Input label="Fare override (optional)" type="number" step="0.01" value={form.fare_override} onChange={(e) => setForm({ ...form, fare_override: e.target.value })} />
            <Input label="Start date" type="date" value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} />
            <Input label="End date (optional)" type="date" value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} />
          </div>
          <div>
            <span className="mb-1 block text-sm font-medium text-slate-700">Runs on</span>
            <div className="flex gap-1.5">
              {DAYS.map((d, i) => (
                <button key={d} type="button" onClick={() => toggleDay(i + 1)}
                  aria-pressed={form.days.includes(i + 1)}
                  className={`h-9 w-9 rounded-lg text-xs font-semibold ${form.days.includes(i + 1) ? 'bg-teal-700 text-white' : 'bg-white ring-1 ring-slate-300'}`}>
                  {d}
                </button>
              ))}
            </div>
          </div>
          <Input label={`Generate trips for next N days`} type="number" min={1} max={90} value={genDays} onChange={(e) => setGenDays(+e.target.value)} />
          {error && <p className="text-sm text-red-600">{error}</p>}
          <Button className="w-full" onClick={() => save.mutate()} disabled={save.isPending || !form.route_id || !form.bus_id || form.days.length === 0 || !form.start_date}>
            Create schedule
          </Button>
        </div>
      </Modal>
    </>
  );
}
