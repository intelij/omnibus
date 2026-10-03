'use client';

import { useMutation } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import { Badge, Button, Card, ErrorBanner, PageHeader } from '@/components/ui';
import { ScanLine } from 'lucide-react';
import { useState } from 'react';

interface VerifyResult {
  valid: boolean;
  already_used: boolean;
  ticket: { passenger_name: string; seat_label: string; status: string };
  booking: { reference: string; status: string; trip: string; departure_at: string; payment_status: string | null };
}

export default function VerifyPage() {
  const [qr, setQr] = useState('');
  const [result, setResult] = useState<VerifyResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  const verify = useMutation({
    mutationFn: (mark: boolean) => api.post<VerifyResult>('/tickets/verify', { qr, mark_used: mark }),
    onSuccess: (r) => { setResult(r); setError(null); },
    onError: (e) => { setResult(null); setError(e instanceof ApiError ? (e.body.message ?? 'Invalid code') : 'Invalid ticket code'); },
  });

  return (
    <>
      <PageHeader title="Verify ticket" subtitle="Paste a scanned QR payload to check or board a passenger" />
      <Card className="max-w-xl">
        <label className="block text-sm font-medium text-ink-secondary">QR payload</label>
        <textarea
          className="mt-1 h-24 w-full rounded-lg border-0 px-3 py-2 font-mono text-xs ring-1 ring-border-strong focus:ring-2 focus:ring-brand"
          placeholder='{"id":12,"token":"…"}'
          value={qr}
          onChange={(e) => setQr(e.target.value)}
          aria-label="QR payload"
        />
        <div className="mt-3 flex gap-2">
          <Button onClick={() => verify.mutate(false)} disabled={!qr || verify.isPending}><ScanLine className="h-4 w-4" /> Check ticket</Button>
          <Button variant="secondary" onClick={() => verify.mutate(true)} disabled={!qr || verify.isPending}>Check &amp; board</Button>
        </div>
        <ErrorBanner message={error} />
      </Card>

      {result && (
        <Card className={`mt-6 max-w-xl ${result.valid ? 'ring-emerald-300' : result.already_used ? 'ring-amber-300' : 'ring-red-300'}`}>
          <div className="flex items-center justify-between">
            <h2 className="font-semibold">{result.valid ? 'Valid ticket' : result.already_used ? 'Already boarded' : 'Invalid'}</h2>
            <Badge tone={result.valid ? 'green' : result.already_used ? 'yellow' : 'red'}>{result.ticket.status}</Badge>
          </div>
          <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
            <div><dt className="text-xs text-ink-faint">Passenger</dt><dd className="font-medium">{result.ticket.passenger_name}</dd></div>
            <div><dt className="text-xs text-ink-faint">Seat</dt><dd className="font-medium">{result.ticket.seat_label}</dd></div>
            <div><dt className="text-xs text-ink-faint">Booking</dt><dd className="font-medium">{result.booking.reference}</dd></div>
            <div><dt className="text-xs text-ink-faint">Trip</dt><dd className="font-medium">{result.booking.trip}</dd></div>
            <div><dt className="text-xs text-ink-faint">Departure</dt><dd className="font-medium">{new Date(result.booking.departure_at).toLocaleString()}</dd></div>
            <div><dt className="text-xs text-ink-faint">Payment</dt><dd className="font-medium">{result.booking.payment_status ?? '—'}</dd></div>
          </dl>
        </Card>
      )}
    </>
  );
}
