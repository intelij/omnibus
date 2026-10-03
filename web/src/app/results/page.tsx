'use client';

import { useQuery } from '@tanstack/react-query';
import { api, SearchResult } from '@/lib/api';
import { PublicShell } from '@/components/PublicShell';
import { Badge, Button, Card, EmptyState, Spinner } from '@/components/ui';
import { ArrowRight, Armchair, Clock, Wifi } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useState } from 'react';

function Results() {
  const params = useSearchParams();
  const router = useRouter();
  const from = params.get('from') ?? '';
  const to = params.get('to') ?? '';
  const date = params.get('date') ?? '';
  const passengers = +(params.get('passengers') ?? 1);
  const [sort, setSort] = useState('departure');

  const { data, isLoading } = useQuery({
    queryKey: ['search', from, to, date, passengers, sort],
    queryFn: () => api.get<{ data: SearchResult[] }>(`/public/trips/search?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&date=${date}&passengers=${passengers}&sort=${sort}`),
    enabled: !!from && !!to && !!date,
  });

  return (
    <PublicShell>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold">{from} → {to}</h1>
          <p className="text-sm text-ink-faint">{new Date(date).toDateString()} · {passengers} passenger{passengers > 1 ? 's' : ''}</p>
        </div>
        <select className="rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-border-strong" value={sort} onChange={(e) => setSort(e.target.value)} aria-label="Sort results">
          <option value="departure">Earliest departure</option>
          <option value="price">Lowest price</option>
          <option value="duration">Shortest trip</option>
        </select>
      </div>

      {isLoading && <Spinner />}

      {!isLoading && (data?.data.length ?? 0) === 0 && (
        <EmptyState title="No trips found" hint="Try a different date or route." />
      )}

      <div className="space-y-4">
        {data?.data.map((r) => (
          <Card key={r.trip.id} className={!r.bookable ? 'opacity-60' : ''}>
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div className="flex items-center gap-6">
                <div>
                  <p className="text-lg font-bold">{new Date(r.trip.departure_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</p>
                  <p className="text-sm text-ink-faint">{r.boarding_stop.name}</p>
                </div>
                <ArrowRight className="h-5 w-5 text-slate-300" aria-hidden />
                <div>
                  <p className="text-lg font-bold">{r.trip.arrival_estimate_at ? new Date(r.trip.arrival_estimate_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'}</p>
                  <p className="text-sm text-ink-faint">{r.dropoff_stop.name}</p>
                </div>
              </div>
              <div className="flex items-center gap-2 text-sm text-ink-faint">
                <Clock className="h-4 w-4" aria-hidden />
                {r.trip.route?.duration_minutes ? `${Math.floor(r.trip.route.duration_minutes / 60)}h ${r.trip.route.duration_minutes % 60}m` : ''}
                {r.trip.bus?.amenities?.includes('wifi') && <Wifi className="h-4 w-4" aria-label="Wi-Fi" />}
              </div>
              <div className="text-right">
                <p className="text-2xl font-bold">{r.trip.currency ?? 'USD'} {r.fare}</p>
                <p className="flex items-center justify-end gap-1 text-sm text-ink-faint">
                  <Armchair className="h-4 w-4" /> {r.available_seats} seats left
                </p>
                <Badge tone={r.bookable ? 'green' : 'red'}>{r.bookable ? 'Available' : 'Sold out'}</Badge>
              </div>
            </div>
            <div className="mt-4 flex justify-end">
              <Button
                disabled={!r.bookable}
                onClick={() => router.push(`/trip/${r.trip.id}?boarding=${r.boarding_stop.sequence}&dropoff=${r.dropoff_stop.sequence}&boarding_stop_id=${r.boarding_stop.id}&dropoff_stop_id=${r.dropoff_stop.id}&passengers=${passengers}`)}
              >
                Select seats <ArrowRight className="h-4 w-4" />
              </Button>
            </div>
          </Card>
        ))}
      </div>
    </PublicShell>
  );
}

export default function ResultsPage() {
  return (
    <Suspense fallback={<PublicShell><Spinner /></PublicShell>}>
      <Results />
    </Suspense>
  );
}
