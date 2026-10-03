'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, Booking, errMsg } from '@/lib/api';
import { Badge, Button, Card, ErrorBanner, PageHeader, Spinner, statusTone } from '@/components/ui';
import { Mail } from 'lucide-react';
import { useParams } from 'next/navigation';
import { useState } from 'react';

export default function BookingDetailPage() {
  const { id } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const [error, setError] = useState<string | null>(null);

  const { data: res, isLoading } = useQuery({
    queryKey: ['admin-booking', id],
    queryFn: () => api.get<{ data: Booking }>(`/bookings/${id}`),
  });

  const cancel = useMutation({
    mutationFn: (reason: string) => api.post(`/bookings/${id}/cancel`, { reason }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin-booking', id] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Cancel failed')),
  });

  const resend = useMutation({
    mutationFn: () => api.post(`/bookings/${id}/resend-ticket`),
    onError: (e: unknown) => setError(errMsg(e, 'Resend failed')),
  });

  const booking = res?.data;
  if (isLoading || !booking) return <Spinner />;

  const events: { id: number; event: string; actor_type: string | null; created_at: string }[] = res?.data?.events ?? [];

  return (
    <>
      <PageHeader title={`Booking ${booking.reference}`} subtitle={booking.trip?.route?.name}>
        <Badge tone={statusTone(booking.status)}>{booking.status.replace('_', ' ')}</Badge>
      </PageHeader>
      <ErrorBanner message={error} />

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <h2 className="mb-3 font-semibold">Details</h2>
          <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <Field k="Contact" v={`${booking.contact.name} · ${booking.contact.phone}`} />
            <Field k="Departure" v={booking.trip?.departure_at ? new Date(booking.trip.departure_at).toLocaleString() : '—'} />
            <Field k="Boarding" v={booking.boarding?.name} />
            <Field k="Drop-off" v={booking.dropoff?.name} />
            <Field k="Seats" v={booking.seats?.map((s) => s.label).join(', ')} />
            <Field k="Total" v={`${booking.currency} ${booking.total.toFixed(2)}`} />
            <Field k="Payment" v={booking.payment ? `${booking.payment.method} — ${booking.payment.status}` : 'none'} />
            <Field k="Created" v={new Date(booking.created_at).toLocaleString()} />
          </dl>
          <div className="mt-5 flex gap-2 border-t border-border pt-4">
            {['held', 'pending_payment', 'confirmed'].includes(booking.status) && (
              <Button variant="danger" onClick={() => cancel.mutate(prompt('Cancellation reason?') ?? 'Operator action')} disabled={cancel.isPending}>
                Cancel booking
              </Button>
            )}
            <Button variant="secondary" onClick={() => resend.mutate()} disabled={resend.isPending}>
              <Mail className="h-4 w-4" /> Resend ticket
            </Button>
          </div>
        </Card>

        <Card>
          <h2 className="mb-3 font-semibold">Timeline</h2>
          <ul className="space-y-3 text-sm">
            {events.map((e) => (
              <li key={e.id} className="flex gap-3">
                <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand" aria-hidden />
                <div>
                  <p className="font-medium">{e.event.replace('_', ' ')}</p>
                  <p className="text-xs text-ink-faint">{new Date(e.created_at).toLocaleString()}{e.actor_type ? ` · ${e.actor_type}` : ''}</p>
                </div>
              </li>
            ))}
            {events.length === 0 && <li className="text-ink-faint">No events recorded.</li>}
          </ul>
        </Card>
      </div>
    </>
  );
}

function Field({ k, v }: { k: string; v?: string | null }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-ink-faint">{k}</dt>
      <dd className="mt-0.5 font-medium">{v ?? '—'}</dd>
    </div>
  );
}
