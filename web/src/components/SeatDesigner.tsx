'use client';

import { clsx } from 'clsx';
import { DoorOpen, Eraser, MonitorX, Toilet, Accessibility, Star } from 'lucide-react';
import { useMemo, useState } from 'react';

export type CellType = 'standard' | 'premium' | 'vip' | 'disabled_access' | 'driver' | 'toilet' | 'stairs' | 'exit' | 'empty';

export interface DesignCell {
  deck: number;
  row: number;
  col: number;
  type: CellType;
  position: 'window' | 'aisle' | 'middle' | 'none';
  price_modifier: number;
  label: string;
}

const PALETTE: { type: CellType; label: string; icon?: React.ReactNode }[] = [
  { type: 'standard', label: 'Standard seat' },
  { type: 'premium', label: 'Premium (+$)' },
  { type: 'vip', label: 'VIP' },
  { type: 'disabled_access', label: 'Accessible', icon: <Accessibility className="h-3.5 w-3.5" /> },
  { type: 'driver', label: 'Driver', icon: <MonitorX className="h-3.5 w-3.5" /> },
  { type: 'toilet', label: 'Toilet', icon: <Toilet className="h-3.5 w-3.5" /> },
  { type: 'stairs', label: 'Stairs', icon: <DoorOpen className="h-3.5 w-3.5" /> },
  { type: 'exit', label: 'Exit', icon: <DoorOpen className="h-3.5 w-3.5" /> },
  { type: 'empty', label: 'Empty space', icon: <Eraser className="h-3.5 w-3.5" /> },
];

const CELL_STYLE: Record<CellType, string> = {
  standard: 'bg-surface ring-1 ring-border-strong text-ink-secondary',
  premium: 'bg-sky-50 ring-2 ring-sky-400 text-sky-800',
  vip: 'bg-amber-50 ring-2 ring-amber-400 text-amber-800',
  disabled_access: 'bg-purple-50 ring-2 ring-purple-400 text-purple-800',
  driver: 'bg-slate-700 text-white',
  toilet: 'bg-slate-300 text-ink-faint',
  stairs: 'bg-slate-300 text-ink-faint',
  exit: 'bg-slate-300 text-ink-faint',
  empty: 'bg-transparent',
};

const CELL_ICON: Partial<Record<CellType, React.ReactNode>> = {
  driver: <MonitorX className="h-4 w-4" />,
  toilet: <Toilet className="h-4 w-4" />,
  stairs: <DoorOpen className="h-4 w-4" />,
  exit: <DoorOpen className="h-4 w-4" />,
  vip: <Star className="h-3.5 w-3.5" />,
  disabled_access: <Accessibility className="h-4 w-4" />,
};

function positionFor(col: number, cols: number): DesignCell['position'] {
  if (col === 1 || col === cols) return 'window';
  if (col === 2 || col === cols - 1) return 'aisle';
  return 'middle';
}

export function SeatDesigner({
  rows, cols, cells, onChange,
}: {
  rows: number;
  cols: number;
  cells: DesignCell[];
  onChange: (cells: DesignCell[]) => void;
}) {
  const [tool, setTool] = useState<CellType>('standard');
  const [paintMode, setPaintMode] = useState<'paint' | 'erase'>('paint');

  const cellMap = useMemo(() => {
    const map = new Map<string, DesignCell>();
    cells.forEach((c) => map.set(`${c.deck}-${c.row}-${c.col}`, c));
    return map;
  }, [cells]);

  const paint = (row: number, col: number) => {
    const key = `1-${row}-${col}`;
    const existing = cellMap.get(key);

    if (paintMode === 'erase' || tool === 'empty') {
      onChange(cells.filter((c) => !(c.deck === 1 && c.row === row && c.col === col)));
      return;
    }

    const next: DesignCell = {
      deck: 1, row, col,
      type: tool,
      position: existing?.position ?? positionFor(col, cols),
      price_modifier: tool === 'premium' ? 5 : tool === 'vip' ? 10 : 0,
      label: existing?.label ?? '',
    };
    onChange([...cells.filter((c) => `${c.deck}-${c.row}-${c.col}` !== key), next]);
  };

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-1.5" role="toolbar" aria-label="Cell tools">
        {PALETTE.map((p) => (
          <button
            key={p.type}
            type="button"
            onClick={() => { setTool(p.type); setPaintMode('paint'); }}
            aria-pressed={tool === p.type && paintMode === 'paint'}
            className={clsx(
              'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1',
              tool === p.type && paintMode === 'paint' ? 'bg-brand text-white ring-brand-hover' : 'bg-surface text-ink-muted ring-border-strong hover:bg-surface-muted',
            )}
          >
            {p.icon}{p.label}
          </button>
        ))}
      </div>

      <div
        className="inline-grid gap-1.5 rounded-xl bg-surface-muted p-4"
        style={{ gridTemplateColumns: `repeat(${cols}, 2.75rem)` }}
        role="grid"
        aria-label="Seat layout editor"
      >
        {Array.from({ length: rows }, (_, r) => r + 1).flatMap((row) =>
          Array.from({ length: cols }, (_, c) => c + 1).map((col) => {
            const cell = cellMap.get(`1-${row}-${col}`);
            return (
              <button
                key={`${row}-${col}`}
                type="button"
                role="gridcell"
                aria-label={cell ? `Row ${row} col ${col}: ${cell.type}` : `Row ${row} col ${col}: empty`}
                onClick={() => paint(row, col)}
                className={clsx(
                  'flex h-11 flex-col items-center justify-center rounded-md text-[10px] font-semibold transition-colors',
                  'focus-visible:outline-2 focus-visible:outline-brand',
                  CELL_STYLE[cell?.type ?? 'empty'],
                  !cell && 'border border-dashed border-border-strong hover:border-teal-500',
                )}
              >
                {CELL_ICON[cell?.type ?? 'empty'] ?? null}
                {cell && ['standard', 'premium', 'vip'].includes(cell.type) && <span>{cell.label || '·'}</span>}
              </button>
            );
          }),
        )}
      </div>
    </div>
  );
}
