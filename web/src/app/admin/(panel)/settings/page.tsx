'use client';

import { useMutation, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { Button, Card, Input, PageHeader, Spinner } from '@/components/ui';
import { useEffect, useState } from 'react';

interface Settings {
  branding: Record<string, string>;
  booking_rules: Record<string, unknown>;
  payment_methods: string[];
  available_gateways: { key: string; label: string; available: boolean }[];
  plan: string;
}

export default function SettingsPage() {
    const [brand, setBrand] = useState<Record<string, string>>({});
  interface CancellationTier { min_hours_before: number; percent: number }
  const [rules, setRules] = useState<{ seat_hold_minutes?: number; pending_payment_minutes?: number; max_seats_per_booking?: number; booking_cutoff_minutes?: number; cancellation_policy?: CancellationTier[] }>({});
  const [methods, setMethods] = useState<string[]>([]);
  const [saved, setSaved] = useState<string | null>(null);

  const { data, isLoading } = useQuery({ queryKey: ['settings'], queryFn: () => api.get<Settings>('/settings') });

  useEffect(() => {
    if (data) {
      /* eslint-disable react-hooks/set-state-in-effect -- hydrate form once data arrives */
      setBrand(data.branding ?? {});
      setRules(data.booking_rules ?? {});
      setMethods(data.payment_methods ?? []);
      /* eslint-enable react-hooks/set-state-in-effect */
    }
  }, [data]);

  const flash = () => { setSaved('Saved'); setTimeout(() => setSaved(null), 2500); };

  const saveBranding = useMutation({ mutationFn: () => api.patch('/settings/branding', brand), onSuccess: flash });
  const saveRules = useMutation({ mutationFn: () => api.patch('/settings/rules', rules), onSuccess: flash });
  const saveMethods = useMutation({ mutationFn: () => api.patch('/settings/payments', { methods }), onSuccess: flash });

  if (isLoading || !data) return <Spinner />;

  return (
    <>
      <PageHeader title="Settings" subtitle={`Plan: ${data.plan}`}>
        {saved && <span className="text-sm font-medium text-emerald-700">{saved}</span>}
      </PageHeader>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <h2 className="mb-4 font-semibold">Branding</h2>
          <div className="space-y-4">
            {(['primary_color', 'secondary_color', 'accent_color'] as const).map((k) => (
              <div key={k} className="flex items-center gap-3">
                <input type="color" className="h-10 w-14 cursor-pointer rounded ring-1 ring-slate-200" value={brand[k] ?? '#0f766e'} onChange={(e) => setBrand({ ...brand, [k]: e.target.value })} aria-label={k.replace('_', ' ')} />
                <span className="text-sm capitalize">{k.replace('_', ' ')}</span>
                <code className="ml-auto text-xs text-slate-400">{brand[k]}</code>
              </div>
            ))}
            <Input label="Header text" value={brand.header_text ?? ''} onChange={(e) => setBrand({ ...brand, header_text: e.target.value })} />
            <Input label="Footer text" value={brand.footer_text ?? ''} onChange={(e) => setBrand({ ...brand, footer_text: e.target.value })} />
            <Button onClick={() => saveBranding.mutate()} disabled={saveBranding.isPending}>Save branding</Button>
          </div>
        </Card>

        <Card>
          <h2 className="mb-4 font-semibold">Payment methods</h2>
          <div className="space-y-2">
            {data.available_gateways.map((g) => (
              <label key={g.key} className="flex items-center gap-3 rounded-lg p-3 ring-1 ring-slate-200">
                <input type="checkbox" className="h-4 w-4 accent-teal-700" checked={methods.includes(g.key)}
                  onChange={(e) => setMethods(e.target.checked ? [...methods, g.key] : methods.filter((m) => m !== g.key))} />
                <span className="flex-1 text-sm font-medium">{g.label}</span>
                {!g.available && g.key !== 'cash' && <span className="text-xs text-amber-600">credentials not configured</span>}
              </label>
            ))}
          </div>
          <Button className="mt-4" onClick={() => saveMethods.mutate()} disabled={saveMethods.isPending || methods.length === 0}>Save methods</Button>
        </Card>

        <Card className="lg:col-span-2">
          <h2 className="mb-4 font-semibold">Booking rules</h2>
          <div className="grid gap-4 sm:grid-cols-3">
            <Input label="Seat hold (minutes)" type="number" value={rules.seat_hold_minutes ?? 10} onChange={(e) => setRules({ ...rules, seat_hold_minutes: +e.target.value })} />
            <Input label="Payment window (minutes)" type="number" value={rules.pending_payment_minutes ?? 30} onChange={(e) => setRules({ ...rules, pending_payment_minutes: +e.target.value })} />
            <Input label="Max seats per booking" type="number" value={rules.max_seats_per_booking ?? 10} onChange={(e) => setRules({ ...rules, max_seats_per_booking: +e.target.value })} />
            <Input label="Booking cutoff (min before departure)" type="number" value={rules.booking_cutoff_minutes ?? 60} onChange={(e) => setRules({ ...rules, booking_cutoff_minutes: +e.target.value })} />
          </div>
          <div className="mt-4">
            <span className="mb-2 block text-sm font-medium">Cancellation policy (refund % by hours before departure)</span>
            <div className="space-y-2">
              {(rules.cancellation_policy ?? []).map((w, i) => (
                <div key={i} className="flex items-center gap-2 text-sm">
                  <span>≥</span>
                  <input type="number" className="w-20 rounded-lg border-0 px-2 py-1.5 ring-1 ring-slate-300" value={w.min_hours_before}
                    onChange={(e) => setRules({ ...rules, cancellation_policy: rules.cancellation_policy!.map((x, j) => j === i ? { ...x, min_hours_before: +e.target.value } : x) })} aria-label="Hours before departure" />
                  <span>hours before departure → refund</span>
                  <input type="number" min={0} max={100} className="w-20 rounded-lg border-0 px-2 py-1.5 ring-1 ring-slate-300" value={w.percent}
                    onChange={(e) => setRules({ ...rules, cancellation_policy: rules.cancellation_policy!.map((x, j) => j === i ? { ...x, percent: +e.target.value } : x) })} aria-label="Refund percent" />
                  <span>%</span>
                  <button type="button" className="text-red-500" onClick={() => setRules({ ...rules, cancellation_policy: rules.cancellation_policy!.filter((_, j) => j !== i) })}>Remove</button>
                </div>
              ))}
              <button type="button" className="text-sm text-teal-700" onClick={() => setRules({ ...rules, cancellation_policy: [...(rules.cancellation_policy ?? []), { min_hours_before: 0, percent: 0 }] })}>+ Add tier</button>
            </div>
          </div>
          <Button className="mt-4" onClick={() => saveRules.mutate()} disabled={saveRules.isPending}>Save rules</Button>
        </Card>
      </div>
    </>
  );
}
