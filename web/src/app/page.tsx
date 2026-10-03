'use client';

import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { PublicShell, useBrand } from '@/components/PublicShell';
import { CitySelect } from '@/components/CitySelect';
import { PassengerStepper } from '@/components/PassengerStepper';
import { safeBgForWhiteText, textOn } from '@/lib/brand';
import { Button, Card } from '@/components/ui';
import { CalendarDays, Search } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';

export default function HomePage() {
  const router = useRouter();
  const { tenant, primary, secondary } = useBrand();
  const { data: endpoints } = useQuery({
    queryKey: ['endpoints'],
    queryFn: () => api.get<{ origins: string[]; destinations: string[] }>('/public/endpoints'),
  });
  const maxPassengers = (tenant?.rules?.max_seats_per_booking as number | undefined) ?? 10;

  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [date, setDate] = useState(() => new Date(Date.now() + 86400000).toISOString().slice(0, 10));
  const [passengers, setPassengers] = useState(1);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!from || !to || !date) return;
    router.push(`/results?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&date=${date}&passengers=${passengers}`);
  };

  const heroA = safeBgForWhiteText(primary);
  const heroB = safeBgForWhiteText(secondary);
  const heroText = textOn(heroA) === '#ffffff' ? '#ffffff' : '#0f172a';

  return (
    <PublicShell>
      <section className="rounded-2xl px-6 py-12 sm:px-12" style={{ background: `linear-gradient(135deg, ${heroA}, ${heroB})`, color: heroText }}>
        <h1 className="text-3xl font-bold sm:text-4xl">Where to next?</h1>
        <p className="mt-2" style={{ color: heroText === '#ffffff' ? 'rgba(255,255,255,0.85)' : 'rgba(15,23,42,0.75)' }}>Search, pick your seat, and get your ticket in under a minute.</p>

        <form onSubmit={submit} className="mt-8 rounded-xl bg-surface p-4 text-ink shadow-lg sm:p-5">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <CitySelect
              label="From"
              value={from}
              onChange={setFrom}
              options={(endpoints?.origins ?? []).map((name) => ({ name }))}
              exclude={to || undefined}
              placeholder="Search departure city…"
              required
            />
            <CitySelect
              label="To"
              value={to}
              onChange={setTo}
              options={(endpoints?.destinations ?? []).map((name) => ({ name }))}
              exclude={from || undefined}
              placeholder="Search destination city…"
              required
            />
            <label className="block">
              <span className="mb-1 flex items-center gap-1 text-sm font-medium text-ink-secondary"><CalendarDays className="h-3.5 w-3.5" aria-hidden /> Date</span>
              <input type="date" className="w-full rounded-lg border-0 px-3 py-2.5 text-sm text-ink ring-1 ring-border-strong focus:ring-2 focus:ring-brand"
                value={date} min={new Date().toISOString().slice(0, 10)} onChange={(e) => setDate(e.target.value)} required />
            </label>
            <PassengerStepper value={passengers} onChange={setPassengers} max={maxPassengers} />
          </div>
          {from && to && from === to && (
            <p role="alert" className="mt-3 text-sm text-red-600">Origin and destination must be different cities.</p>
          )}
          <div className="mt-4 flex justify-end">
            <Button type="submit" disabled={!from || !to || from === to} className="w-full sm:w-auto">
              <Search className="h-4 w-4" aria-hidden /> Search trips
            </Button>
          </div>
        </form>
      </section>

      <section className="mt-10 grid gap-4 sm:grid-cols-3">
        {[
          ['Pick your exact seat', 'Interactive seat map — window, aisle, front or back.'],
          ['Instant e-ticket', 'QR-coded ticket delivered to your phone and email.'],
          ['Pay your way', 'Card, EcoCash, InBucks or cash at the depot.'],
        ].map(([title, body]) => (
          <Card key={title}>
            <h3 className="font-semibold">{title}</h3>
            <p className="mt-1 text-sm text-ink-faint">{body}</p>
          </Card>
        ))}
      </section>
    </PublicShell>
  );
}

