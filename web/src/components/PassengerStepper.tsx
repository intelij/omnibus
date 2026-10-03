'use client';

import { Minus, Plus, Users } from 'lucide-react';

/**
 * Numeric passenger control — real input with large touch targets for
 * mobile booking. Min 1; max comes from the tenant's booking rule.
 */
export function PassengerStepper({
  value,
  onChange,
  max = 10,
  label = 'Passengers',
}: {
  value: number;
  onChange: (n: number) => void;
  max?: number;
  label?: React.ReactNode;
}) {
  const clamp = (n: number) => Math.max(1, Math.min(max, Number.isFinite(n) ? n : 1));

  const btn = 'flex h-10 w-10 items-center justify-center rounded-lg bg-surface text-ink ring-1 ring-border-strong transition hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-brand';

  return (
    <div>
      <span className="mb-1 flex items-center gap-1 text-sm font-medium text-ink-secondary">
        <Users className="h-3.5 w-3.5" aria-hidden /> {label}
      </span>
      <div className="flex items-center gap-2">
        <button type="button" aria-label="One fewer passenger" className={btn}
          disabled={value <= 1} onClick={() => onChange(clamp(value - 1))}>
          <Minus className="h-4 w-4" aria-hidden />
        </button>
        <input
          type="number"
          inputMode="numeric"
          min={1}
          max={max}
          required
          aria-label={typeof label === 'string' ? label : 'Passengers'}
          className="w-16 rounded-lg border-0 bg-surface py-2.5 text-center text-sm font-semibold text-ink ring-1 ring-border-strong focus:ring-2 focus:ring-brand"
          value={value}
          onChange={(e) => onChange(clamp(parseInt(e.target.value, 10)))}
          onBlur={(e) => onChange(clamp(parseInt(e.target.value, 10)))}
        />
        <button type="button" aria-label="One more passenger" className={btn}
          disabled={value >= max} onClick={() => onChange(clamp(value + 1))}>
          <Plus className="h-4 w-4" aria-hidden />
        </button>
      </div>
      <p className="mt-1 text-xs text-ink-faint">Max {max} per booking</p>
    </div>
  );
}
