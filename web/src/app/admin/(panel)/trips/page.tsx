'use client';

import { useQuery } from '@tanstack/react-query';
import { api, Paginated, Trip } from '@/lib/api';
import { DataTable, FilterBar, Pagination } from '@/components/admin';
import { Badge, PageHeader, Spinner, statusTone } from '@/components/ui';
import Link from 'next/link';
import { useState } from 'react';

export default function TripsPage() {
  const [status, setStatus] = useState('');
  const [date, setDate] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading } = useQuery({
    queryKey: ['trips', status, date, page],
    queryFn: () => api.get<Paginated<Trip>>(`/trips?status=${status}&date_from=${date}&page=${page}`),
  });

  return (
    <>
      <PageHeader title="Trips" subtitle="Scheduled departures and operations" />

      <FilterBar>
        <select className="rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-border-strong" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} aria-label="Filter by status">
          <option value="">All statuses</option>
          {['scheduled', 'boarding', 'departed', 'in_transit', 'arrived', 'delayed', 'cancelled', 'completed'].map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <input type="date" className="rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-border-strong" value={date} onChange={(e) => { setDate(e.target.value); setPage(1); }} aria-label="Filter by date" />
      </FilterBar>

      {isLoading ? <Spinner /> : (
        <>
          <DataTable
            head={['Departure', 'Route', 'Bus', 'Status', 'Actions']}
            rows={(data?.data ?? []).map((t) => [
              new Date(t.departure_at).toLocaleString(),
              <span key="r" className="font-medium">{t.route?.name}</span>,
              t.bus?.registration ?? '—',
              <Badge key="s" tone={statusTone(t.status)}>{t.status}</Badge>,
              <Link key="a" href={`/admin/trips/${t.id}`} className="text-sm font-medium text-brand hover:underline">Manage</Link>,
            ])}
          />
          <Pagination meta={data?.meta} onPage={setPage} />
        </>
      )}
    </>
  );
}
