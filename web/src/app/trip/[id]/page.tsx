'use client';

import { useQuery } from '@tanstack/react-query';
import { api, SeatCell, Trip } from '@/lib/api';
import { PublicShell, useTenant } from '@/components/PublicShell';
import { SeatMap } from '@/components/SeatMap';
import { Badge, Button, Card, Spinner } from '@/components/ui';
import { useParams, useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useMemo, useState } from 'react';

function TripPage() {
  const { id } = useParams<{ id: string }>();
  const params = useSearchParams();
  const router = useRouter();
  const { data: tenant } = useTenant();

  const boarding = +(params.get('boarding') ?? 0);
  const dropoff = +(params.get('dropoff') ?? 99);
  const passengers = Math.min(10, +(params.get('passengers') ?? 1));
  const boardingStopId = params.get('boarding_stop_id');
  const dropoffStopId = params.get('dropoff_stop_id');

  const { data: trip } = useQuery({
    queryKey: ['trip', id],
    queryFn: () => api.get<{ data: Trip } | Trip>(`/public/trips/${id}`).then((r) => ('data' in r ? r.data : r)),
  });

  const { data: seatData, isLoading } = useQuery({
    queryKey: ['seats', id, boarding, dropoff],
    queryFn: () => api.get<{ seats: SeatCell[]; hold_minutes: number }>(`/public/trips/${id}/seats?boarding=${boarding}&dropoff=${dropoff}`),
  });

  const [selected, setSelected] = useState<SeatCell[]>([]);
  const [capNotice, setCapNotice] = useState(false);

  const toggle = (seat: SeatCell) => {
    setSelected((prev) => {
      if (prev.some((s) => s.id === seat.id)) {
        setCapNotice(false);
        return prev.filter((s) => s.id !== seat.id);
      }
      if (prev.length >= passengers) {
        setCapNotice(true); // spec: block the extra seat, don't silently swap
        return prev;
      }
      setCapNotice(false);
      return [...prev, seat];
    });
  };

  const fareTotal = useMemo(
    () => selected.reduce((sum, s) => sum + ((trip?.base_fare ?? 0) + s.price_modifier), 0),
    [selected, trip],
  );

  const stops = trip?.route?.stops ?? [];
  const boardingStop = stops.find((s) => s.sequence === boarding);
  const dropoffStop = stops.find((s) => s.sequence === dropoff);

  const proceed = () => {
    const state = {
      trip_id: +id,
      boarding_stop_id: boardingStopId,
      dropoff_stop_id: dropoffStopId,
      boarding_sequence: boarding,
      dropoff_sequence: dropoff,
      trip_seat_ids: selected.map((s) => s.id),
      seat_labels: selected.map((s) => s.label),
    };
    sessionStorage.setItem('booking_draft', JSON.stringify(state));
    router.push('/checkout');
  };

  return (
    <PublicShell>
      <div className="grid gap-8 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <h1 className="text-xl font-bold">Choose your seats</h1>
          <p className="mt-1 text-sm text-ink-faint">
            {boardingStop?.name} → {dropoffStop?.name} · {trip?.departure_at && new Date(trip.departure_at).toLocaleString()} · {passengers} passenger{passengers > 1 ? 's' : ''}
          </p>
          <p className="mt-2 text-sm font-medium text-ink-secondary" role="status" aria-live="polite">
            Select {passengers} seat{passengers > 1 ? 's' : ''} — Selected: {selected.length} / {passengers}
          </p>
          {capNotice && (
            <p role="alert" className="mt-1 text-sm text-amber-700">
              {passengers} seat{passengers > 1 ? 's' : ''} selected — deselect one or increase passengers to pick another.
            </p>
          )}
          <div className="mt-6">
            {isLoading ? <Spinner /> : <SeatMap seats={seatData?.seats ?? []} selected={selected.map((s) => s.id)} onToggle={toggle} />}
          </div>
        </div>

        <aside className="lg:col-span-1">
          <Card className="sticky top-6">
            <h2 className="font-semibold">Your selection</h2>
            {selected.length === 0 ? (
              <p className="mt-2 text-sm text-ink-faint">
                Pick {passengers} seat{passengers > 1 ? 's' : ''} on the map, or continue to let us allocate automatically.
              </p>
            ) : (
              <div className="mt-3 flex flex-wrap gap-2">
                {selected.map((s) => <Badge key={s.id} tone="green">Seat {s.label}</Badge>)}
              </div>
            )}
            <div className="mt-4 border-t border-border pt-4 text-sm">
              <div className="flex justify-between"><span>Seats</span><span>{selected.length || passengers}</span></div>
              <div className="mt-1 flex justify-between font-semibold">
                <span>Est. total</span>
                <span>{tenant?.currency ?? 'USD'} {selected.length ? fareTotal.toFixed(2) : ((trip?.base_fare ?? 0) * passengers).toFixed(2)}</span>
              </div>
            </div>
            <Button className="mt-4 w-full" onClick={proceed} disabled={selected.length !== 0 && selected.length !== passengers}>
              {selected.length ? `Continue with seat${selected.length > 1 ? 's' : ''} ${selected.map((s) => s.label).join(', ')}` : 'Auto-allocate seats'}
            </Button>
            <p className="mt-3 text-center text-xs text-ink-faint">
              Seats are held for {seatData?.hold_minutes ?? 10} minutes while you check out.
            </p>
          </Card>
        </aside>
      </div>

      {/* Mobile sticky action bar — selection + CTA stays in reach. */}
      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-surface p-3 shadow-lg lg:hidden">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-3">
          <span className="text-sm font-medium text-ink-secondary">Selected {selected.length} / {passengers}</span>
          <Button onClick={proceed} disabled={selected.length !== 0 && selected.length !== passengers}>
            {selected.length ? 'Continue' : 'Auto-allocate'}
          </Button>
        </div>
      </div>
      <div className="h-20 lg:hidden" aria-hidden />
    </PublicShell>
  );
}

export default function Page() {
  return (
    <Suspense fallback={<PublicShell><Spinner /></PublicShell>}>
      <TripPage />
    </Suspense>
  );
}
