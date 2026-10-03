'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, Paginated, errMsg } from '@/lib/api';
import { DataTable } from '@/components/admin';
import { Badge, Button, Card, Input, PageHeader, Spinner, statusTone } from '@/components/ui';
import { DesignCell, SeatDesigner } from '@/components/SeatDesigner';
import { Plus } from 'lucide-react';
import { useState } from 'react';

interface LayoutRow { id: number; name: string; decks: number; rows: number; cols: number; status: string; seats_count?: number }

export default function SeatLayoutsPage() {
  const qc = useQueryClient();
  const [creating, setCreating] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [name, setName] = useState('New layout');
  const [rows, setRows] = useState(11);
  const [cols, setCols] = useState(5); // 5 cols → classic 2+aisle+2
  const [cells, setCells] = useState<DesignCell[]>([]);
  const [error, setError] = useState<string | null>(null);
  // ZW coach quick-generate config
  const [gen, setGen] = useState({ rows: 11, left: 2, right: 2, rear: 5 });

  const { data, isLoading } = useQuery({
    queryKey: ['layouts'],
    queryFn: () => api.get<Paginated<LayoutRow>>('/seat-layouts?per_page=50'),
  });

  const save = useMutation({
    mutationFn: () => {
      // Auto-label bookable seats in row-major order.
      let n = 1;
      const seats = cells
        .filter((c) => c.type !== 'empty')
        .map((c) => ({
          ...c,
          label: ['standard', 'premium', 'vip', 'disabled_access', 'staff'].includes(c.type) ? String(n++) : c.type.toUpperCase(),
          type: c.type === 'empty' ? 'standard' : c.type,
        }));
      return api.post('/seat-layouts', { name, decks: 1, rows, cols, seats });
    },
    onSuccess: () => { setCreating(false); setCells([]); qc.invalidateQueries({ queryKey: ['layouts'] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Save failed')),
  });

  const publish = useMutation({
    mutationFn: (id: number) => api.post(`/seat-layouts/${id}/publish`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['layouts'] }),
  });

  /** ZW coach flow: create draft → server generates the seat cells. */
  const generate = useMutation({
    mutationFn: async () => {
      const cols = gen.left + gen.right + (gen.left > 0 && gen.right > 0 ? 1 : 0);
      const created = await api.post<{ id: number }>('/seat-layouts', {
        name, decks: 1, rows: gen.rows + (gen.rear > 0 ? 1 : 0), cols, seats: [],
      });
      return api.post(`/seat-layouts/${created.id}/generate`, {
        rows: gen.rows, left_seats: gen.left, right_seats: gen.right, rear_row_seats: gen.rear,
      });
    },
    onSuccess: () => { setGenerating(false); setGen({ rows: 11, left: 2, right: 2, rear: 5 }); qc.invalidateQueries({ queryKey: ['layouts'] }); setError(null); },
    onError: (e: unknown) => setError(errMsg(e, 'Generation failed')),
  });

  const genCols = gen.left + gen.right + (gen.left > 0 && gen.right > 0 ? 1 : 0);
  const genCapacity = gen.rows * (gen.left + gen.right) + gen.rear;

  return (
    <>
      <PageHeader title="Seat layouts" subtitle="Design layouts once — reuse across your fleet">
        <Button variant="secondary" onClick={() => { setGenerating(true); setCreating(false); }}>ZW coach config</Button>
        <Button onClick={() => { setCreating(true); setGenerating(false); }}><Plus className="h-4 w-4" /> Design layout</Button>
      </PageHeader>

      {generating && (
        <Card className="mb-6">
          <h2 className="mb-3 font-semibold">Bus seating configuration</h2>
          <div className="grid gap-4 sm:grid-cols-5">
            <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} />
            <Input label="Standard rows" type="number" min={1} max={40} value={gen.rows}
              onChange={(e) => setGen({ ...gen, rows: +e.target.value })} />
            <Input label="Left of aisle" type="number" min={0} max={4} value={gen.left}
              onChange={(e) => setGen({ ...gen, left: +e.target.value })} />
            <Input label="Right of aisle" type="number" min={0} max={4} value={gen.right}
              onChange={(e) => setGen({ ...gen, right: +e.target.value })} />
            <Input label="Rear row seats" type="number" min={0} max={10} value={gen.rear}
              onChange={(e) => setGen({ ...gen, rear: +e.target.value })} />
          </div>
          <p className="mt-3 text-sm text-ink-secondary">
            {gen.rows} row{gen.rows > 1 ? 's' : ''} × {gen.left}+{gen.right} = {gen.rows * (gen.left + gen.right)} seats
            {gen.rear > 0 && <> · rear row {gen.rear}</>} ·{' '}
            <span className="font-semibold">Total capacity: {genCapacity}</span>
          </p>
          {/* Live preview — aisle column shaded, rear row spans the width. */}
          <div className="mt-4 inline-grid gap-1 rounded-lg bg-surface-muted p-3" aria-label="Layout preview"
            style={{ gridTemplateColumns: `repeat(${Math.max(genCols, gen.rear)}, 1.5rem)` }}>
            {Array.from({ length: gen.rows }, (_, r) =>
              Array.from({ length: Math.max(genCols, gen.rear) }, (_, c) => {
                const isAisle = gen.left > 0 && gen.right > 0 && c === gen.left;
                const isSeat = !isAisle && c < genCols && ((c < gen.left) || (c > gen.left));
                return <div key={`${r}-${c}`} className={isAisle || !isSeat ? 'h-6' : 'h-6 rounded bg-surface ring-1 ring-border-strong'} />;
              }),
            )}
            {gen.rear > 0 && Array.from({ length: Math.max(genCols, gen.rear) }, (_, c) => (
              <div key={`rear-${c}`} className={c < gen.rear ? 'h-6 rounded bg-brand-soft ring-1 ring-brand' : 'h-6'} />
            ))}
          </div>
          <div className="mt-4 flex gap-2">
            <Button onClick={() => generate.mutate()} disabled={generate.isPending || gen.left + gen.right === 0}>
              {generate.isPending ? 'Generating…' : 'Generate layout'}
            </Button>
            <Button variant="ghost" onClick={() => setGenerating(false)}>Cancel</Button>
          </div>
          {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
        </Card>
      )}

      {creating && (
        <Card className="mb-6">
          <div className="mb-4 grid gap-4 sm:grid-cols-4">
            <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} />
            <Input label="Rows" type="number" min={1} max={40} value={rows} onChange={(e) => setRows(+e.target.value)} />
            <Input label="Columns" type="number" min={1} max={10} value={cols} onChange={(e) => setCols(+e.target.value)} />
            <div className="flex items-end gap-2">
              <Button onClick={() => save.mutate()} disabled={save.isPending || cells.length === 0}>Save draft</Button>
              <Button variant="ghost" onClick={() => { setCreating(false); setCells([]); }}>Cancel</Button>
            </div>
          </div>
          <SeatDesigner rows={rows} cols={cols} cells={cells} onChange={setCells} />
          <p className="mt-3 text-xs text-ink-faint">{cells.filter((c) => ['standard', 'premium', 'vip', 'disabled_access'].includes(c.type)).length} bookable seats · labels auto-assigned on save</p>
          {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
        </Card>
      )}

      {isLoading ? <Spinner /> : (
        <DataTable
          head={['Name', 'Grid', 'Seats', 'Status', 'Actions']}
          rows={(data?.data ?? []).map((l) => [
            <span key="n" className="font-medium">{l.name}</span>,
            `${l.rows}×${l.cols} · ${l.decks} deck${l.decks > 1 ? 's' : ''}`,
            l.seats_count ?? '—',
            <Badge key="s" tone={statusTone(l.status)}>{l.status}</Badge>,
            <div key="a" className="flex gap-3">
              {l.status === 'draft' && <button className="text-sm font-medium text-brand hover:underline" onClick={() => publish.mutate(l.id)}>Publish</button>}
            </div>,
          ])}
        />
      )}
    </>
  );
}
