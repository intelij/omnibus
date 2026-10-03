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
  const [name, setName] = useState('New layout');
  const [rows, setRows] = useState(11);
  const [cols, setCols] = useState(5); // 5 cols → classic 2+aisle+2
  const [cells, setCells] = useState<DesignCell[]>([]);
  const [error, setError] = useState<string | null>(null);

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

  return (
    <>
      <PageHeader title="Seat layouts" subtitle="Design layouts once — reuse across your fleet">
        <Button onClick={() => setCreating(true)}><Plus className="h-4 w-4" /> Design layout</Button>
      </PageHeader>

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
