'use client';

import { useMutation, useQuery } from '@tanstack/react-query';
import { api, ApiError, Booking, Ticket } from '@/lib/api';
import { PublicShell } from '@/components/PublicShell';
import { Badge, Button, Card, ErrorBanner, Input, Spinner, statusTone } from '@/components/ui';
import { CheckCircle2, XCircle } from 'lucide-react';
import { useParams } from 'next/navigation';
import { Suspense, useEffect, useState } from 'react';
import QRCode from 'react-qr-code';

function BookingPage() {
  const { ref } = useParams<{ ref: string }>();
  const [key, setKey] = useState<string | null>(null);
  const [keyInput, setKeyInput] = useState('');
  const [authError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setKey(sessionStorage.getItem(`booking_key_${ref}`));
  }, [ref]);

  const bookingQuery = useQuery({
    queryKey: ['booking', ref, key],
    queryFn: () => api.get<{ data: Booking }>(`/public/bookings/${ref}?email=${encodeURIComponent(key!)}`),
    enabled: !!key,
  });

  const ticketsQuery = useQuery({
    queryKey: ['tickets', ref, key, bookingQuery.data?.data.status],
    queryFn: () => api.get<{ data: Ticket[]; operator: string }>(`/public/bookings/${ref}/tickets?email=${encodeURIComponent(key!)}`),
    enabled: !!key && bookingQuery.isSuccess && ['confirmed', 'completed'].includes(bookingQuery.data.data.status),
  });

  const cancel = useMutation({
    mutationFn: () => api.post(`/public/bookings/${ref}/cancel?email=${encodeURIComponent(key!)}`, {}),
    onSuccess: () => { bookingQuery.refetch(); setMessage('Booking cancelled.'); },
    onError: (e) => setMessage(e instanceof ApiError ? e.body.message ?? 'Could not cancel' : 'Could not cancel'),
  });

  const booking = bookingQuery.data?.data;

  if (key === null) {
    return (
      <PublicShell>
        <Card className="mx-auto max-w-md">
          <h1 className="text-lg font-bold">Find your booking</h1>
          <p className="mt-1 text-sm text-slate-500">Enter the email or phone number used when booking.</p>
          <div className="mt-4 space-y-3">
            <Input label="Email or phone" value={keyInput} onChange={(e) => setKeyInput(e.target.value)} />
            <ErrorBanner message={authError} />
            <Button className="w-full" onClick={() => keyInput && setKey(keyInput)}>View booking</Button>
          </div>
        </Card>
      </PublicShell>
    );
  }

  if (bookingQuery.isError) {
    return (
      <PublicShell>
        <Card className="mx-auto max-w-md">
          <h1 className="text-lg font-bold">Booking {ref}</h1>
          <ErrorBanner message="We couldn't verify your details. Enter the exact email or phone used at booking." />
          <Input label="Email or phone" value={keyInput} onChange={(e) => setKeyInput(e.target.value)} />
          <Button className="mt-3 w-full" onClick={() => { setKey(null); setKey(keyInput); }}>Retry</Button>
        </Card>
      </PublicShell>
    );
  }

  if (!booking) return <PublicShell><Spinner /></PublicShell>;

  return (
    <PublicShell>
      <div className="mx-auto max-w-3xl">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold">Booking {booking.reference}</h1>
            <p className="text-sm text-slate-500">{booking.trip?.route?.origin} → {booking.trip?.route?.destination}</p>
          </div>
          <Badge tone={statusTone(booking.status)}>{booking.status.replace('_', ' ')}</Badge>
        </div>

        {message && <div className="mt-4"><ErrorBanner message={message} /></div>}

        {booking.status === 'confirmed' && (
          <div className="mt-4 flex items-center gap-3 rounded-lg bg-emerald-50 p-4 text-emerald-800">
            <CheckCircle2 className="h-5 w-5" aria-hidden />
            <p className="text-sm font-medium">Payment confirmed — your tickets are ready below.</p>
          </div>
        )}
        {['pending_payment', 'held'].includes(booking.status) && (
          <div className="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
            <p className="font-medium">Payment pending.</p>
            {booking.payment?.instructions && <p className="mt-1">{booking.payment.instructions}</p>}
          </div>
        )}

        <Card className="mt-6">
          <div className="grid gap-4 text-sm sm:grid-cols-2">
            <div><span className="text-slate-500">Departure</span><p className="font-medium">{booking.trip?.departure_at ? new Date(booking.trip.departure_at).toLocaleString() : '—'}</p></div>
            <div><span className="text-slate-500">Route</span><p className="font-medium">{booking.boarding?.name} → {booking.dropoff?.name}</p></div>
            <div><span className="text-slate-500">Passenger(s)</span><p className="font-medium">{booking.passengers?.map((p) => p.name).join(', ')}</p></div>
            <div><span className="text-slate-500">Seats</span><p className="font-medium">{booking.seats?.map((s) => s.label).join(', ')}</p></div>
            <div><span className="text-slate-500">Total</span><p className="font-medium">{booking.currency} {booking.total.toFixed(2)}{booking.discount > 0 && <span className="ml-2 text-xs text-emerald-700">(-{booking.discount.toFixed(2)} with {booking.promotion_code})</span>}</p></div>
            <div><span className="text-slate-500">Payment</span><p className="font-medium">{booking.payment ? `${booking.payment.method} — ${booking.payment.status}` : '—'}</p></div>
          </div>
        </Card>

        {ticketsQuery.data && ticketsQuery.data.data.length > 0 && (
          <div className="mt-6">
            <h2 className="mb-3 font-semibold">Tickets</h2>
            <div className="grid gap-4 sm:grid-cols-2">
              {ticketsQuery.data.data.map((t) => (
                <Card key={t.id} className="flex items-center gap-4">
                  <div className="rounded-lg bg-white p-2 ring-1 ring-slate-200">
                    <QRCode value={t.qr} size={96} aria-label={`Ticket QR for ${t.passenger_name}`} />
                  </div>
                  <div className="text-sm">
                    <p className="font-semibold">{t.passenger_name}</p>
                    <p className="text-slate-500">Seat {t.seat_label}</p>
                    <Badge tone={statusTone(t.status)}>{t.status}</Badge>
                  </div>
                </Card>
              ))}
            </div>
          </div>
        )}

        {['held', 'pending_payment', 'confirmed'].includes(booking.status) && (
          <div className="mt-6 flex justify-end gap-3">
            <Button variant="danger" onClick={() => cancel.mutate()} disabled={cancel.isPending}>
              <XCircle className="h-4 w-4" /> Cancel booking
            </Button>
          </div>
        )}
      </div>
    </PublicShell>
  );
}

export default function Page() {
  return (
    <Suspense fallback={<PublicShell><Spinner /></PublicShell>}>
      <BookingPage />
    </Suspense>
  );
}
