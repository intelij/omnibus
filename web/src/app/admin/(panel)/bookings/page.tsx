'use client';

import { useQuery } from '@tanstack/react-query';
import { api, Booking, Paginated } from '@/lib/api';
import { DataTable, FilterBar, Pagination } from '@/components/admin';
import { Badge, PageHeader, Spinner, statusTone } from '@/components/ui';
import Link from 'next/link';
import { useState } from 'react';

export default function BookingsPage() {
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading } = useQuery({
    queryKey: ['bookings', status, q, page],
    queryFn: () => api.get<Paginated<Booking>>(`/bookings?status=${status}&q=${encodeURIComponent(q)}&page=${page}`),
  });

  return (
    <>
      <PageHeader title="Bookings" subtitle="Reservations across all sales channels" />
      <FilterBar>
        <input className="w-64 rounded-lg border-0 bg-white px-3 py-2 text-sm ring-1 ring-slate-300" placeholder="Search reference, name, email…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} aria-label="Search bookings" />
        <select className="rounded-lg border-0 bg-white px-3 py-2 text-sm ring-1 ring-slate-300" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} aria-label="Filter by status">
          <option value="">All</option>
          {['held', 'pending_payment', 'confirmed', 'cancelled', 'expired', 'completed', 'refunded'].map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
        </select>
      </FilterBar>

      {isLoading ? <Spinner /> : (
        <>
          <DataTable
            head={['Reference', 'Passenger', 'Departure', 'Route', 'Total', 'Status', 'Actions']}
            rows={(data?.data ?? []).map((b) => [
              <span key="ref" className="font-mono font-medium">{b.reference}</span>,
              b.contact.name,
              b.trip?.departure_at ? new Date(b.trip.departure_at).toLocaleString() : '—',
              b.trip?.route ? `${b.trip.route.origin}→${b.trip.route.destination}` : '—',
              `${b.currency} ${b.total.toFixed(2)}`,
              <Badge key="s" tone={statusTone(b.status)}>{b.status.replace('_', ' ')}</Badge>,
              <Link key="a" href={`/admin/bookings/${b.id}`} className="text-sm font-medium text-teal-700 hover:underline">Open</Link>,
            ])}
          />
          <Pagination meta={data?.meta} onPage={setPage} />
        </>
      )}
    </>
  );
}
