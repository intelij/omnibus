'use client';

import { useMutation, useQuery } from '@tanstack/react-query';
import { api, Paginated, SeatCell, Trip, errMsg } from '@/lib/api';
import { SeatMap } from '@/components/SeatMap';
import { PassengerStepper } from '@/components/PassengerStepper';
import { Button, Card, ErrorBanner, Input, PageHeader, Spinner } from '@/components/ui';
import Link from 'next/link';
import { useState } from 'react';

interface Stop { id: number; name: string; sequence: number }

/** Agent walk-up booking — multi-passenger in one transaction. */
export default function NewBookingPage() {
  const [tripId, setTripId] = useState<number | null>(null);
  const [trip, setTrip] = useState<Trip | null>(null);
  const [boarding, setBoarding] = useState(0);
  const [dropoff, setDropoff] = useState(0);
  const [pax, setPax] = useState(1);
  const [selected, setSelected] = useState<SeatCell[]>([]);
  const [contact, setContact] = useState({ name: '', email: '', phone: '' });
  const [passengers, setPassengers] = useState<{ first: string; last: string; phone: string }[]>([{ first: '', last: '', phone: '' }]);
  const [method, setMethod] = useState('cash');
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const { data: trips } = useQuery({
    queryKey: ['agent-trips'],
    queryFn: () => api.get<Paginated<Trip>>('/trips?status=scheduled&per_page=50'),
  });

  const { data: seatData, isLoading: seatsLoading } = useQuery({
    queryKey: ['agent-seats', tripId, boarding, dropoff],
    enabled: !!tripId,
    queryFn: () => api.get<{ seats: SeatCell[] }>(`/trips/${tripId}/seats?boarding=${boarding}&dropoff=${dropoff}`),
  });

  const stops: Stop[] = trip?.route?.stops ?? [];

  const pickTrip = (id: number) => {
    const t = (trips?.data ?? []).find((x) => x.id === id) ?? null;
    setTripId(id); setTrip(t); setSelected([]);
    setBoarding(t?.route?.stops?.[0]?.sequence ?? 0);
    setDropoff(t?.route?.stops?.[t.route.stops.length - 1]?.sequence ?? 0);
  };

  const setPaxCount = (n: number) => {
    if (n < selected.length) {
      // Spec: never silently discard selected seats — make the user confirm.
      const ok = window.confirm(
        `You currently have ${selected.length} seats selected. Reducing passengers to ${n} will remove ${selected.length - n} seat selection(s). Continue?`,
      );
      if (!ok) return;
      setSelected((s) => s.slice(0, n));
    }
    setPax(n);
    setPassengers((prev) => Array.from({ length: n }, (_, i) => prev[i] ?? { first: '', last: '', phone: '' }));
  };

  const toggle = (seat: SeatCell) => setSelected((prev) =>
    prev.some((s) => s.id === seat.id) ? prev.filter((s) => s.id !== seat.id)
      : prev.length < pax ? [...prev, seat] : prev,
  );

  const book = useMutation({
    mutationFn: () => api.post<{ data: { reference: string } }>('/bookings', {
      trip_id: tripId,
      boarding_stop_id: stops.find((s) => s.sequence === boarding)?.id,
      dropoff_stop_id: stops.find((s) => s.sequence === dropoff)?.id,
      boarding_sequence: boarding,
      dropoff_sequence: dropoff,
      contact_name: contact.name,
      contact_email: contact.email || undefined,
      contact_phone: contact.phone,
      payment_method: method,
      trip_seat_ids: selected.length ? selected.map((s) => s.id) : undefined,
      passengers: passengers.map((p) => ({
        first_name: p.first, last_name: p.last, phone: p.phone || undefined,
      })),
    }),
    onSuccess: (r) => { setDone(r.data.reference); setError(null); },
    onError: (e) => setError(errMsg(e, 'Booking failed')),
  });

  if (done) {
    return (
      <>
        <PageHeader title="Booking created" />
        <Card>
          <p className="text-lg font-semibold">Reference <span className="font-mono">{done}</span></p>
          <p className="mt-1 text-sm text-ink-faint">
            {method === 'cash' ? 'Cash payment is pending — confirm receipt from Payments.' : 'Payment initiated.'}
          </p>
          <div className="mt-4 flex gap-3">
            <Link href={`/admin/bookings`} className="text-sm font-medium text-brand hover:underline">Back to bookings</Link>
            <button className="text-sm font-medium text-brand hover:underline" onClick={() => { setDone(null); setSelected([]); }}>Book another</button>
          </div>
        </Card>
      </>
    );
  }

  return (
    <>
      <PageHeader title="New booking" subtitle="Agent-assisted walk-up / phone booking" />
      <ErrorBanner message={error} />

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card>
            <div className="grid gap-4 sm:grid-cols-2">
              <label className="block sm:col-span-2">
                <span className="mb-1 block text-sm font-medium text-ink-secondary">Trip</span>
                <select required className="w-full rounded-lg border-0 bg-surface px-3 py-2.5 text-sm text-ink ring-1 ring-border-strong"
                  value={tripId ?? ''} onChange={(e) => pickTrip(+e.target.value)}>
                  <option value="">Select an upcoming trip…</option>
                  {(trips?.data ?? []).map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.route?.name} · {new Date(t.departure_at).toLocaleString()} · {t.bus?.registration}
                    </option>
                  ))}
                </select>
              </label>
              <label className="block">
                <span className="mb-1 block text-sm font-medium text-ink-secondary">Boarding</span>
                <select className="w-full rounded-lg border-0 bg-surface px-3 py-2.5 text-sm text-ink ring-1 ring-border-strong"
                  value={boarding} onChange={(e) => setBoarding(+e.target.value)} disabled={!trip}>
                  {stops.filter((s) => s.sequence < dropoff).map((s) => <option key={s.id} value={s.sequence}>{s.name}</option>)}
                </select>
              </label>
              <label className="block">
                <span className="mb-1 block text-sm font-medium text-ink-secondary">Drop-off</span>
                <select className="w-full rounded-lg border-0 bg-surface px-3 py-2.5 text-sm text-ink ring-1 ring-border-strong"
                  value={dropoff} onChange={(e) => setDropoff(+e.target.value)} disabled={!trip}>
                  {stops.filter((s) => s.sequence > boarding).map((s) => <option key={s.id} value={s.sequence}>{s.name}</option>)}
                </select>
              </label>
            </div>
          </Card>

          <Card>
            <div className="mb-3 flex items-center justify-between">
              <h2 className="font-semibold">Seats — {selected.length} / {pax}</h2>
              <PassengerStepper value={pax} onChange={setPaxCount} />
            </div>
            {!tripId ? <p className="text-sm text-ink-faint">Select a trip to load the seat map.</p>
              : seatsLoading ? <Spinner />
              : <SeatMap seats={seatData?.seats ?? []} selected={selected.map((s) => s.id)} onToggle={toggle} />}
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <h2 className="mb-3 font-semibold">Contact</h2>
            <div className="space-y-3">
              <Input label="Name" value={contact.name} required onChange={(e) => setContact({ ...contact, name: e.target.value })} />
              <Input label="Phone" value={contact.phone} required onChange={(e) => setContact({ ...contact, phone: e.target.value })} />
              <Input label="Email (optional)" type="email" value={contact.email} onChange={(e) => setContact({ ...contact, email: e.target.value })} />
            </div>
          </Card>

          <Card>
            <h2 className="mb-3 font-semibold">Passengers</h2>
            <div className="space-y-3">
              {passengers.map((p, i) => (
                <div key={i} className="rounded-lg bg-surface-muted p-3">
                  <p className="mb-2 text-xs font-semibold text-ink-faint">
                    Passenger {i + 1}{selected[i] ? ` · Seat ${selected[i].label}` : ''}
                  </p>
                  <div className="grid grid-cols-2 gap-2">
                    <input required aria-label={`Passenger ${i + 1} first name`} placeholder="First name"
                      className="rounded-lg border-0 bg-surface px-2.5 py-2 text-sm text-ink ring-1 ring-border-strong"
                      value={p.first} onChange={(e) => setPassengers(passengers.map((x, j) => j === i ? { ...x, first: e.target.value } : x))} />
                    <input required aria-label={`Passenger ${i + 1} last name`} placeholder="Last name"
                      className="rounded-lg border-0 bg-surface px-2.5 py-2 text-sm text-ink ring-1 ring-border-strong"
                      value={p.last} onChange={(e) => setPassengers(passengers.map((x, j) => j === i ? { ...x, last: e.target.value } : x))} />
                  </div>
                </div>
              ))}
            </div>
          </Card>

          <Card>
            <label className="block">
              <span className="mb-1 block text-sm font-medium text-ink-secondary">Payment method</span>
              <select className="w-full rounded-lg border-0 bg-surface px-3 py-2.5 text-sm text-ink ring-1 ring-border-strong"
                value={method} onChange={(e) => setMethod(e.target.value)}>
                <option value="cash">Cash</option>
                <option value="card">Card</option>
                <option value="ecocash">EcoCash</option>
                <option value="inbucks">InBucks</option>
              </select>
            </label>
            <Button className="mt-4 w-full" onClick={() => book.mutate()}
              disabled={book.isPending || !tripId || !contact.name || !contact.phone
                || passengers.some((p) => !p.first || !p.last)
                || (selected.length > 0 && selected.length !== pax)}>
              {book.isPending ? 'Booking…' : `Create booking${selected.length ? ` — ${selected.map((s) => s.label).join(', ')}` : ' (auto seats)'}`}
            </Button>
            {selected.length > 0 && selected.length !== pax && (
              <p className="mt-2 text-xs text-amber-700">Select {pax} seat{pax > 1 ? 's' : ''} or clear the selection for auto-allocation.</p>
            )}
          </Card>
        </div>
      </div>
    </>
  );
}
