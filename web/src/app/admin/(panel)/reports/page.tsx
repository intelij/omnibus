'use client';

import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { DataTable, FilterBar } from '@/components/admin';
import { Badge, Card, PageHeader, Spinner } from '@/components/ui';
import { useState } from 'react';

interface Revenue { by_method: { method: string; count: number; total: number }[]; total: number; refunded: number }
interface Occupancy { trip_id: number; route: string; departure_at: string; seats: number; booked: number; occupancy: number }

export default function ReportsPage() {
  const [from, setFrom] = useState(() => new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10));
  const [to, setTo] = useState(() => new Date().toISOString().slice(0, 10));

  const { data: revenue } = useQuery({ queryKey: ['rep-revenue', from, to], queryFn: () => api.get<Revenue>(`/reports/revenue?date_from=${from}&date_to=${to}`) });
  const { data: occupancy, isLoading } = useQuery({ queryKey: ['rep-occupancy', from, to], queryFn: () => api.get<Occupancy[]>(`/reports/occupancy?date_from=${from}&date_to=${to}`) });
  const { data: agents } = useQuery({ queryKey: ['rep-agents', from, to], queryFn: () => api.get<{ user_id: number; name: string; bookings: number; revenue: number }[]>(`/reports/agents?date_from=${from}&date_to=${to}`) });
  const { data: payments } = useQuery({ queryKey: ['rep-payments', from, to], queryFn: () => api.get<{ failed: number; expired: number; pending: number }>(`/reports/payments?date_from=${from}&date_to=${to}`) });

  return (
    <>
      <PageHeader title="Reports" subtitle="Revenue, occupancy, agents, payment health" />
      <FilterBar>
        <input type="date" className="rounded-lg border-0 bg-white px-3 py-2 text-sm ring-1 ring-slate-300" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="From date" />
        <input type="date" className="rounded-lg border-0 bg-white px-3 py-2 text-sm ring-1 ring-slate-300" value={to} onChange={(e) => setTo(e.target.value)} aria-label="To date" />
      </FilterBar>

      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <Card><p className="text-2xl font-bold">${(revenue?.total ?? 0).toFixed(2)}</p><p className="text-xs text-slate-500">Gross revenue</p></Card>
        <Card><p className="text-2xl font-bold">${(revenue?.refunded ?? 0).toFixed(2)}</p><p className="text-xs text-slate-500">Refunded</p></Card>
        <Card><p className="text-2xl font-bold">{payments ? `${payments.failed + payments.expired}` : '—'}</p><p className="text-xs text-slate-500">Failed + expired payments</p></Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <div>
          <h2 className="mb-3 font-semibold">Revenue by method</h2>
          <DataTable head={['Method', 'Count', 'Total']}
            rows={(revenue?.by_method ?? []).map((m) => [m.method, String(m.count), `$${(+m.total).toFixed(2)}`])}
            empty="No payments in window" />
        </div>
        <div>
          <h2 className="mb-3 font-semibold">Agent performance</h2>
          <DataTable head={['Agent', 'Bookings', 'Revenue']}
            rows={(agents ?? []).map((a) => [a.name, String(a.bookings), `$${(+a.revenue).toFixed(2)}`])}
            empty="No agent bookings" />
        </div>
      </div>

      <h2 className="mb-3 mt-8 font-semibold">Trip occupancy</h2>
      {isLoading ? <Spinner /> : (
        <DataTable head={['Trip', 'Route', 'Departure', 'Seats', 'Booked', 'Occupancy']}
          rows={(occupancy ?? []).map((o) => [
            `#${o.trip_id}`, o.route, new Date(o.departure_at).toLocaleString(), String(o.seats), String(o.booked),
            <Badge key="o" tone={o.occupancy >= 80 ? 'green' : o.occupancy >= 40 ? 'yellow' : 'gray'}>{o.occupancy}%</Badge>,
          ])}
          empty="No trips in window" />
      )}
    </>
  );
}
