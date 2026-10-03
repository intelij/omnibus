'use client';

import { useQuery } from '@tanstack/react-query';
import { api, getUser, UserProfile } from '@/lib/api';
import { Badge, Card, Spinner } from '@/components/ui';
import { PageHeader } from '@/components/ui';
import { Banknote, BusFront, ClipboardList, LifeBuoy } from 'lucide-react';
import { Suspense, useEffect, useState } from 'react';

interface Dashboard {
  role: string;
  widgets: {
    operations?: {
      today: { trips: number; bookings: number; revenue: number; pending_cash: number };
      upcoming_departures: { id: number; departure_at: string; status: string }[];
    };
    finance?: { failed: number; expired: number; pending: number };
    support?: { open_tickets: number };
  };
}

function Dashboard() {
  const [user, setUser] = useState<UserProfile | null>(null);
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setUser(getUser());
  }, []);

  const { data, isLoading } = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api.get<Dashboard>('/dashboard'),
  });

  const { data: onboarding } = useQuery({
    queryKey: ['onboarding'],
    queryFn: () => api.get<{ complete: boolean; completed: number; total: number; remediation: string[] }>('/onboarding'),
  });

  if (isLoading) return <Spinner />;

  const ops = data?.widgets.operations;

  return (
    <>
      <PageHeader title={`Welcome back, ${user?.name ?? ''}`} subtitle={user?.tenant?.name ?? ''} />

      {onboarding && !onboarding.complete && (
        <Card className="mb-6 border-amber-200 bg-amber-50">
          <h3 className="font-semibold text-amber-900">Setup checklist — {onboarding.completed}/{onboarding.total} complete</h3>
          <div className="mt-2 flex flex-wrap gap-2">
            {onboarding.remediation.map((step) => <Badge key={step} tone="yellow">{step}</Badge>)}
          </div>
        </Card>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {ops && (
          <>
            <Stat icon={BusFront} label="Departures today" value={String(ops.today.trips)} />
            <Stat icon={ClipboardList} label="Bookings today" value={String(ops.today.bookings)} />
            <Stat icon={Banknote} label="Revenue today" value={`$${ops.today.revenue.toFixed(2)}`} />
            <Stat icon={Banknote} label="Cash awaiting confirmation" value={`$${ops.today.pending_cash.toFixed(2)}`} />
          </>
        )}
        {data?.widgets.finance && (
          <Stat icon={Banknote} label="Failed payments (30d)" value={String(data.widgets.finance.failed)} />
        )}
        {data?.widgets.support && (
          <Stat icon={LifeBuoy} label="Open support tickets" value={String(data.widgets.support.open_tickets)} />
        )}
      </div>

      {ops && (
        <Card className="mt-6">
          <h2 className="mb-3 font-semibold">Upcoming departures</h2>
          <ul className="divide-y divide-border text-sm">
            {ops.upcoming_departures.map((t) => (
              <li key={t.id} className="flex items-center justify-between py-2">
                <span>Trip #{t.id}</span>
                <span className="text-ink-faint">{new Date(t.departure_at).toLocaleString()}</span>
                <Badge tone="blue">{t.status}</Badge>
              </li>
            ))}
            {ops.upcoming_departures.length === 0 && <li className="py-4 text-ink-faint">No upcoming departures.</li>}
          </ul>
        </Card>
      )}
    </>
  );
}

function Stat({ icon: Icon, label, value }: { icon: React.ComponentType<{ className?: string }>; label: string; value: string }) {
  return (
    <Card>
      <div className="flex items-center gap-3">
        <div className="rounded-lg bg-brand-soft p-2.5"><Icon className="h-5 w-5 text-brand" /></div>
        <div>
          <p className="text-2xl font-bold">{value}</p>
          <p className="text-xs text-ink-faint">{label}</p>
        </div>
      </div>
    </Card>
  );
}

export default function Page() {
  return (
    <Suspense fallback={<Spinner />}>
      <Dashboard />
    </Suspense>
  );
}
