'use client';

import { PublicShell } from '@/components/PublicShell';
import { Button, Card, Input } from '@/components/ui';
import { useRouter } from 'next/navigation';
import { Suspense, useState } from 'react';

function Track() {
  const router = useRouter();
  const [reference, setReference] = useState('');
  const [key, setKey] = useState('');

  return (
    <PublicShell>
      <Card className="mx-auto max-w-md">
        <h1 className="text-lg font-bold">Manage your booking</h1>
        <p className="mt-1 text-sm text-ink-faint">Enter your booking reference and the email or phone you booked with.</p>
        <div className="mt-4 space-y-4">
          <Input label="Booking reference" placeholder="TH-XXXXXX" value={reference} onChange={(e) => setReference(e.target.value.toUpperCase())} />
          <Input label="Email or phone" value={key} onChange={(e) => setKey(e.target.value)} />
          <Button
            className="w-full"
            disabled={!reference || !key}
            onClick={() => {
              sessionStorage.setItem(`booking_key_${reference}`, key);
              router.push(`/booking/${reference}`);
            }}
          >
            Find booking
          </Button>
        </div>
      </Card>
    </PublicShell>
  );
}

export default function TrackPage() {
  return (
    <Suspense fallback={<PublicShell />}>
      <Track />
    </Suspense>
  );
}
