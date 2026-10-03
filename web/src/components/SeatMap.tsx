'use client';

import { SeatCell } from '@/lib/api';
import { clsx } from 'clsx';
import { Accessibility, Check, DoorOpen, Lock, MonitorX, Star, Toilet, X } from 'lucide-react';

const NON_SEAT: Record<string, { icon: React.ReactNode; label: string }> = {
  driver: { icon: <MonitorX className="h-4 w-4" />, label: 'Driver' },
  toilet: { icon: <Toilet className="h-4 w-4" />, label: 'Toilet' },
  stairs: { icon: <DoorOpen className="h-4 w-4" />, label: 'Stairs' },
  exit: { icon: <DoorOpen className="h-4 w-4" />, label: 'Exit' },
  kitchen: { icon: null, label: 'Galley' },
};

function stateLabel(seat: SeatCell): string {
  const parts = [`Seat ${seat.label}`, seat.state];
  if (seat.type === 'vip') parts.push('VIP');
  if (seat.type === 'premium') parts.push('Premium');
  if (seat.type === 'disabled_access') parts.push('Accessible');
  if (seat.price_modifier > 0) parts.push(`+${seat.price_modifier}`);
  return parts.join(', ');
}

function SeatIcon({ seat, selected }: { seat: SeatCell; selected: boolean }) {
  if (selected) return <Check className="h-4 w-4" aria-hidden />;
  if (seat.type === 'disabled_access') return <Accessibility className="h-3.5 w-3.5" aria-hidden />;
  if (seat.state === 'held') return <Lock className="h-3.5 w-3.5" aria-hidden />;
  if (seat.state === 'booked') return <X className="h-3.5 w-3.5" aria-hidden />;
  return <>{seat.label}</>;
}

export function SeatMap({
  seats,
  selected,
  onToggle,
  readonly = false,
}: {
  seats: SeatCell[];
  selected: number[];
  onToggle: (seat: SeatCell) => void;
  maxSeats?: number;
  readonly?: boolean;
}) {
  const decks = [...new Set(seats.map((s) => s.deck))].sort();

  return (
    <div className="space-y-8">
      {decks.map((deck) => {
        const deckSeats = seats.filter((s) => s.deck === deck);
        const rows = [...new Set(deckSeats.map((s) => s.row))].sort((a, b) => a - b);
        const cols = Math.max(...deckSeats.map((s) => s.col), 1);

        return (
          <div key={deck}>
            {decks.length > 1 && <h3 className="mb-3 text-sm font-semibold text-ink-muted">{deck === 1 ? 'Lower deck' : 'Upper deck'}</h3>}
            <div
              role="grid"
              aria-label={readonly ? 'Seat occupancy' : 'Select seats'}
              className="inline-grid gap-1.5 rounded-xl bg-surface-muted p-4"
              style={{ gridTemplateColumns: `repeat(${cols}, 2.5rem)` }}
            >
              {rows.flatMap((row) =>
                Array.from({ length: cols }, (_, i) => i + 1).map((col) => {
                  const seat = deckSeats.find((s) => s.row === row && s.col === col);
                  if (!seat) return <div key={`${row}-${col}`} className="h-10" />;

                  const isSelected = selected.includes(seat.id);
                  const selectable = seat.state === 'available' && !readonly;

                  if (seat.type in NON_SEAT) {
                    const meta = NON_SEAT[seat.type];
                    return (
                      <div key={seat.id} className="flex h-10 items-center justify-center rounded bg-slate-200 text-ink-faint" title={meta.label} aria-label={meta.label} role="img">
                        {meta.icon}
                      </div>
                    );
                  }

                  return (
                    <button
                      key={seat.id}
                      type="button"
                      role="gridcell"
                      aria-label={stateLabel(seat)}
                      aria-selected={isSelected}
                      aria-disabled={!selectable && !isSelected}
                      disabled={!selectable && !isSelected}
                      title={stateLabel(seat)}
                      onClick={() => (selectable || isSelected) && onToggle(seat)}
                      className={clsx(
                        'relative flex h-10 items-center justify-center rounded-md text-xs font-semibold transition-all',
                        'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand',
                        isSelected && 'bg-brand text-white ring-2 ring-brand-hover',
                        !isSelected && selectable && 'bg-surface text-ink-secondary ring-1 ring-border-strong hover:ring-2 hover:ring-brand',
                        !isSelected && !selectable && 'cursor-not-allowed',
                        !isSelected && seat.state === 'booked' && 'seat-occupied bg-slate-300 text-ink-faint',
                        !isSelected && seat.state === 'held' && 'seat-held border-2 border-amber-400 bg-amber-50 text-amber-800',
                        !isSelected && seat.state === 'unavailable' && 'bg-slate-200 text-ink-faint',
                        seat.type === 'vip' && !isSelected && selectable && 'ring-2 ring-amber-500',
                        seat.type === 'premium' && !isSelected && selectable && 'ring-2 ring-sky-500',
                        seat.type === 'disabled_access' && !isSelected && selectable && 'ring-2 ring-purple-500',
                      )}
                    >
                      {seat.type === 'vip' && !isSelected && (
                        <Star className="absolute -right-1 -top-1 h-3 w-3 fill-amber-400 text-amber-500" aria-hidden />
                      )}
                      <SeatIcon seat={seat} selected={isSelected} />
                    </button>
                  );
                }),
              )}
            </div>
          </div>
        );
      })}

      <ul className="flex flex-wrap gap-4 text-xs text-ink-muted" aria-label="Legend">
        <li className="flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-surface ring-1 ring-border-strong" /> Available</li>
        <li className="flex items-center gap-1.5"><span className="flex h-3 w-3 items-center justify-center rounded bg-brand text-white"><Check className="h-2 w-2" /></span> Selected</li>
        <li className="flex items-center gap-1.5"><span className="seat-occupied flex h-3 w-3 items-center justify-center rounded bg-slate-300"><X className="h-2 w-2 text-ink-faint" /></span> Taken</li>
        <li className="flex items-center gap-1.5"><span className="seat-held h-3 w-3 rounded border-2 border-amber-400 bg-amber-50" /> Held</li>
        <li className="flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-surface ring-2 ring-amber-500" /> VIP</li>
        <li className="flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-surface ring-2 ring-purple-500" /> Accessible</li>
      </ul>
    </div>
  );
}
