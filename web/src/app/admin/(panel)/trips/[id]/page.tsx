'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, SeatCell, Trip, errMsg } from '@/lib/api';
import { DataTable } from '@/components/admin';
import { SeatMap } from '@/components/SeatMap';
import { Badge, Button, Card, ErrorBanner, PageHeader, Select, Spinner, statusTone } from '@/components/ui';
import { useParams } from 'next/navigation';
import { useState } from 'react';

export default function TripDetailPage() {
  const { id } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const [error, setError] = useState<string | null>(null);
  const [cancelReason, setCancelReason] = useState('');
  const [swapBus, setSwapBus] = useState('');

  const { data: tripRes, isLoading } = useQuery({
    queryKey: ['trip', id],
    queryFn: () => api.get<{ data: Trip; booked_seats: number }>(`/trips/${id}`),
  });

  const { data: seats } = useQuery({
    queryKey: ['trip-seats', id],
    queryFn: () => api.get<{ seats: SeatCell[] }>(`/trips/${id}/seats`),
  });

  const { data: manifest } = useQuery({
    queryKey: ['manifest', id],
    queryFn: () => api.get<{ manifest: { reference: string; passenger: string; seat: string; boarding: string; dropoff: string; payment: string }[] }>(`/trips/${id}/manifest`),
  });

  const { data: buses } = useQuery({
    queryKey: ['buses-all'],
    queryFn: () => api.get<{ data: { id: number; registration_number: string }[] }>('/buses?per_page=100'),
  });

  const trip = tripRes?.data;

  const statusMutation = useMutation({
    mutationFn: ({ status, extra }: { status: string; extra?: Record<string, unknown> }) =>
      api.post(`/trips/${id}/status`, { status, ...extra }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['trip', id] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Status update failed')),
  });

  const swap = useMutation({
    mutationFn: () => api.post(`/trips/${id}/swap-bus`, { bus_id: +swapBus }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['trip', id] }); qc.invalidateQueries({ queryKey: ['trip-seats', id] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Bus swap failed')),
  });

  if (isLoading || !trip) return <Spinner />;

  const STATUS_ACTIONS: Record<string, string[]> = {
    scheduled: ['boarding', 'delayed', 'cancelled'],
    boarding: ['departed', 'cancelled'],
    departed: ['in_transit'],
    in_transit: ['arrived'],
    arrived: ['completed'],
    delayed: ['departed', 'cancelled'],
  };

  return (
    <>
      <PageHeader title={`Trip #${trip.id}`} subtitle={`${trip.route?.name} · ${new Date(trip.departure_at).toLocaleString()}`}>
        <Badge tone={statusTone(trip.status)}>{trip.status}</Badge>
      </PageHeader>

      <ErrorBanner message={error} />

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <h2 className="mb-3 font-semibold">Operations</h2>
          <div className="flex flex-wrap gap-2">
            {(STATUS_ACTIONS[trip.status] ?? []).map((s) => (
              <Button key={s} variant={s === 'cancelled' ? 'danger' : 'secondary'}
                onClick={() => statusMutation.mutate({ status: s, extra: { reason: s === 'cancelled' ? cancelReason || 'Operator cancelled' : undefined } })}
                disabled={statusMutation.isPending}>
                Mark {s}
              </Button>
            ))}
            {(STATUS_ACTIONS[trip.status] ?? []).includes('cancelled') && (
              <input className="rounded-lg border-0 px-3 py-2 text-sm ring-1 ring-slate-300" placeholder="Cancellation reason" value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} />
            )}
          </div>

          <div className="mt-6 border-t border-slate-100 pt-4">
            <h3 className="mb-2 text-sm font-semibold">Swap bus</h3>
            <div className="flex gap-2">
              <Select value={swapBus} onChange={(e) => setSwapBus(e.target.value)} aria-label="Replacement bus">
                <option value="">Choose bus…</option>
                {(buses?.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.registration_number}</option>)}
              </Select>
              <Button variant="secondary" disabled={!swapBus || swap.isPending} onClick={() => swap.mutate()}>Swap</Button>
            </div>
            <p className="mt-1 text-xs text-slate-400">Passengers are re-seated automatically on the new layout.</p>
          </div>
        </Card>

        <Card>
          <h2 className="mb-3 font-semibold">Seats ({tripRes?.booked_seats ?? 0} booked)</h2>
          <div className="max-h-96 overflow-y-auto">
            <SeatMap seats={seats?.seats ?? []} selected={[]} onToggle={() => undefined} maxSeats={0} />
          </div>
        </Card>
      </div>

      <Card className="mt-6">
        <h2 className="mb-3 font-semibold">Passenger manifest</h2>
        <DataTable
          head={['Reference', 'Passenger', 'Seat', 'Boarding', 'Drop-off', 'Payment']}
          rows={(manifest?.manifest ?? []).map((m) => [
            m.reference, m.passenger, m.seat, m.boarding, m.dropoff,
            <Badge key="p" tone={statusTone(m.payment ?? '')}>{m.payment ?? '—'}</Badge>,
          ])}
          empty="No confirmed passengers yet."
        />
      </Card>
    </>
  );
}
