'use client';

import { useMutation } from '@tanstack/react-query';
import { api, ApiError, Booking, newIdempotencyKey } from '@/lib/api';
import { PublicShell, useTenant } from '@/components/PublicShell';
import { Badge, Button, Card, ErrorBanner, Input, Select } from '@/components/ui';
import { Tag } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { Suspense, useEffect, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';

const schema = z.object({
  contact_name: z.string().min(2, 'Name required'),
  contact_email: z.string().email().or(z.literal('')),
  contact_phone: z.string().min(7, 'Phone required'),
  passengers: z.array(
    z.object({
      first_name: z.string().min(1, 'Required'),
      last_name: z.string().min(1, 'Required'),
      phone: z.string().optional(),
      id_document: z.string().optional(),
      passenger_type: z.enum(['adult', 'child', 'senior']).default('adult'),
    }),
  ).min(1),
  payment_method: z.enum(['cash', 'card', 'ecocash', 'inbucks']),
  promotion_code: z.string().optional(),
});

type FormValues = z.input<typeof schema>;

function Checkout() {
  const router = useRouter();
  const { data: tenant } = useTenant();
  interface Draft {
    trip_id: number; boarding_stop_id: string | null; dropoff_stop_id: string | null;
    boarding_sequence: number; dropoff_sequence: number;
    trip_seat_ids: number[]; seat_labels: string[];
  }
  const [draft, setDraft] = useState<Draft | null>(null);
  const [promoDiscount, setPromoDiscount] = useState<{ code: string; discount: number } | null>(null);
  const [promoError, setPromoError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const raw = sessionStorage.getItem('booking_draft');
    if (!raw) router.replace('/');
    else {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setDraft(JSON.parse(raw));
    }
  }, [router]);

  const seatCount = draft?.trip_seat_ids?.length || 1;

  const { register, control, handleSubmit, watch, formState: { errors }, setValue } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      payment_method: 'cash',
      passengers: Array.from({ length: seatCount }, () => ({ passenger_type: 'adult' as const })),
    },
  });
  const { fields } = useFieldArray({ control, name: 'passengers' });
  const promoCode = watch('promotion_code');

  useEffect(() => {
    // Keep passenger rows aligned with selected seats / count.
    const want = draft?.trip_seat_ids?.length || 1;
    if (fields.length !== want) {
      const current = control._formValues.passengers ?? [];
      setValue('passengers', Array.from({ length: want }, (_, i) => current[i] ?? { first_name: '', last_name: '', passenger_type: 'adult' as const }));
    }
  }, [draft, fields.length, control, setValue]);

  const applyPromo = async () => {
    setPromoError(null);
    if (!promoCode) return;
    try {
      const r = await api.post<{ code: string; discount: number }>('/public/promotions/validate', {
        code: promoCode,
        trip_id: draft?.trip_id,
      });
      setPromoDiscount({ code: r.code, discount: r.discount });
    } catch (e) {
      setPromoDiscount(null);
      setPromoError(e instanceof ApiError ? (e.body.message ?? 'Invalid code') : 'Invalid code');
    }
  };

  const submit = useMutation({
    mutationFn: async (values: FormValues) => {
      const payload = {
        ...draft,
        ...values,
        promotion_code: values.promotion_code || undefined,
      };
      return api.post<{ data: Booking }>('/public/bookings', payload, {
        headers: { 'Idempotency-Key': newIdempotencyKey() },
      });
    },
    onSuccess: (res) => {
      sessionStorage.removeItem('booking_draft');
      sessionStorage.setItem(`booking_key_${res.data.reference}`, res.data.contact.email ?? res.data.contact.phone);
      router.push(`/booking/${res.data.reference}`);
    },
    onError: (e) => {
      setError(e instanceof ApiError ? (e.body.message ?? 'Booking failed') : 'Booking failed — please try again.');
    },
  });

  if (!draft) return <PublicShell />;

  const methods = tenant?.payment_methods ?? [];

  return (
    <PublicShell>
      <h1 className="text-xl font-bold">Passenger details &amp; payment</h1>
      <p className="mt-1 text-sm text-slate-500">Your seats are being held while you complete checkout.</p>

      <form onSubmit={handleSubmit((v) => submit.mutate(v))} className="mt-6 grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <ErrorBanner message={error} />
          <Card>
            <h2 className="font-semibold">Contact</h2>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <Input label="Full name" {...register('contact_name')} error={errors.contact_name?.message} autoComplete="name" />
              <Input label="Phone (for ticket delivery)" {...register('contact_phone')} error={errors.contact_phone?.message} autoComplete="tel" />
              <Input label="Email (optional)" type="email" {...register('contact_email')} error={errors.contact_email?.message} autoComplete="email" />
            </div>
          </Card>

          {fields.map((field, i) => (
            <Card key={field.id}>
              <div className="flex items-center justify-between">
                <h2 className="font-semibold">Passenger {i + 1}</h2>
                {draft.seat_labels?.[i] && <Badge tone="blue">Seat {draft.seat_labels[i]}</Badge>}
              </div>
              <div className="mt-4 grid gap-4 sm:grid-cols-3">
                <Input label="First name" {...register(`passengers.${i}.first_name`)} error={errors.passengers?.[i]?.first_name?.message} />
                <Input label="Last name" {...register(`passengers.${i}.last_name`)} error={errors.passengers?.[i]?.last_name?.message} />
                <Select label="Type" {...register(`passengers.${i}.passenger_type`)}>
                  <option value="adult">Adult</option>
                  <option value="child">Child</option>
                  <option value="senior">Senior</option>
                </Select>
                <Input label="ID / passport (optional)" {...register(`passengers.${i}.id_document`)} />
                <Input label="Phone (optional)" {...register(`passengers.${i}.phone`)} />
              </div>
            </Card>
          ))}
        </div>

        <aside className="space-y-6">
          <Card>
            <h2 className="font-semibold">Promo code</h2>
            <div className="mt-3 flex gap-2">
              <Input placeholder="e.g. LAUNCH10" {...register('promotion_code')} aria-label="Promo code" />
              <Button type="button" variant="secondary" onClick={applyPromo}><Tag className="h-4 w-4" /></Button>
            </div>
            {promoError && <p className="mt-2 text-xs text-red-600">{promoError}</p>}
            {promoDiscount && <p className="mt-2 text-xs text-emerald-700">Applied {promoDiscount.code} — saves {tenant?.currency} {promoDiscount.discount.toFixed(2)}</p>}
          </Card>

          <Card>
            <h2 className="font-semibold">Payment method</h2>
            <div className="mt-3 space-y-2">
              {methods.filter((m) => m.available !== false).map((m) => (
                <label key={m.key} className="flex cursor-pointer items-center gap-3 rounded-lg p-3 ring-1 ring-slate-200 has-checked:ring-2 has-checked:ring-teal-600">
                  <input type="radio" value={m.key} {...register('payment_method')} className="h-4 w-4 accent-teal-700" />
                  <span className="text-sm font-medium">{m.label}</span>
                </label>
              ))}
              {methods.length === 0 && <p className="text-sm text-slate-500">Payment options unavailable.</p>}
            </div>
            <Button type="submit" className="mt-5 w-full" disabled={submit.isPending}>
              {submit.isPending ? 'Processing…' : 'Confirm booking'}
            </Button>
            <p className="mt-3 text-xs text-slate-400">
              {watch('payment_method') === 'cash'
                ? 'Cash bookings hold your seat — pay at the depot before departure.'
                : 'You will be prompted to complete payment after booking.'}
            </p>
          </Card>
        </aside>
      </form>
    </PublicShell>
  );
}

export default function CheckoutPage() {
  return (
    <Suspense fallback={<PublicShell />}>
      <Checkout />
    </Suspense>
  );
}
