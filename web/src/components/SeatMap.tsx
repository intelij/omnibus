'use client';

import { SeatCell } from '@/lib/api';
import { clsx } from 'clsx';
import { DoorOpen, MonitorX, Toilet } from 'lucide-react';

const NON_SEAT: Record<string, React.ReactNode> = {
  driver: <MonitorX className="h-4 w-4" />,
  toilet: <Toilet className="h-4 w-4" />,
  stairs: <DoorOpen className="h-4 w-4" />,
  exit: <DoorOpen className="h-4 w-4" />,
  kitchen: null,
};

export function SeatMap({
  seats,
  selected,
  onToggle,
}: {
  seats: SeatCell[];
  selected: number[];
  onToggle: (seat: SeatCell) => void;
  maxSeats?: number;
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
            {decks.length > 1 && <h3 className="mb-3 text-sm font-semibold text-slate-600">{deck === 1 ? 'Lower deck' : 'Upper deck'}</h3>}
            <div
              role="grid"
              aria-label="Seat map"
              className="inline-grid gap-1.5 rounded-xl bg-slate-100 p-4"
              style={{ gridTemplateColumns: `repeat(${cols}, 2.5rem)` }}
            >
              {rows.flatMap((row) =>
                Array.from({ length: cols }, (_, i) => i + 1).map((col) => {
                  const seat = deckSeats.find((s) => s.row === row && s.col === col);
                  if (!seat) return <div key={`${row}-${col}`} className="h-10" />;

                  const isSelected = selected.includes(seat.id);
                  const selectable = seat.state === 'available';

                  if (seat.type in NON_SEAT) {
                    return (
                      <div key={seat.id} className="flex h-10 items-center justify-center rounded bg-slate-200 text-slate-400" aria-hidden>
                        {NON_SEAT[seat.type]}
                      </div>
                    );
                  }

                  return (
                    <button
                      key={seat.id}
                      type="button"
                      role="gridcell"
                      aria-label={`Seat ${seat.label}${selectable ? '' : ` (${seat.state})`}`}
                      aria-selected={isSelected}
                      disabled={!selectable && !isSelected}
                      title={`Seat ${seat.label} — ${seat.type}${seat.price_modifier > 0 ? ` (+${seat.price_modifier})` : ''}`}
                      onClick={() => selectable || isSelected ? onToggle(seat) : undefined}
                      className={clsx(
                        'flex h-10 items-center justify-center rounded-md text-xs font-semibold transition-all',
                        'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-teal-600',
                        isSelected && 'bg-teal-600 text-white ring-2 ring-teal-700',
                        !isSelected && selectable && 'bg-white text-slate-700 ring-1 ring-slate-300 hover:ring-2 hover:ring-teal-500',
                        !isSelected && !selectable && 'cursor-not-allowed bg-slate-300 text-slate-400',
                        seat.type === 'vip' && !isSelected && selectable && 'ring-2 ring-amber-400',
                        seat.type === 'premium' && !isSelected && selectable && 'ring-2 ring-sky-400',
                        seat.type === 'disabled_access' && !isSelected && selectable && 'ring-2 ring-purple-400',
                      )}
                    >
                      {seat.label}
                    </button>
                  );
                }),
              )}
            </div>
          </div>
        );
      })}

      <div className="flex flex-wrap gap-4 text-xs text-slate-600" aria-label="Legend">
        <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-white ring-1 ring-slate-300" /> Available</span>
        <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-teal-600" /> Selected</span>
        <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-slate-300" /> Taken</span>
        <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-white ring-2 ring-amber-400" /> VIP</span>
      </div>
    </div>
  );
}
