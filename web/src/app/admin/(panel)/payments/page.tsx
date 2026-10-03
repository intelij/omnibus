'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, Paginated, errMsg } from '@/lib/api';
import { DataTable, FilterBar, Pagination } from '@/components/admin';
import { Badge, Button, Card, ErrorBanner, PageHeader, Spinner, statusTone } from '@/components/ui';
import { useState } from 'react';

interface PaymentRow {
  id: number;
  booking_reference?: string;
  method: string;
  amount: number;
  currency: string;
  status: string;
  provider_ref?: string | null;
  confirmed_at?: string | null;
  created_at: string;
}

export default function PaymentsPage() {
  const qc = useQueryClient();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [refundFor, setRefundFor] = useState<PaymentRow | null>(null);
  const [refundAmount, setRefundAmount] = useState('');
  const [refundReason, setRefundReason] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['payments', status, page],
    queryFn: () => api.get<Paginated<PaymentRow>>(`/payments?status=${status}&page=${page}`),
  });

  const { data: recon } = useQuery({
    queryKey: ['reconciliation'],
    queryFn: () => api.get<{ pending: PaymentRow[]; processing: PaymentRow[]; failed_today: number }>('/reconciliation'),
  });

  const confirm = useMutation({
    mutationFn: (id: number) => api.post(`/payments/${id}/confirm`),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['payments'] }); qc.invalidateQueries({ queryKey: ['reconciliation'] }); },
    onError: (e: unknown) => setError(errMsg(e, 'Confirm failed')),
  });

  const refund = useMutation({
    mutationFn: () => api.post(`/payments/${refundFor!.id}/refund`, { amount: +refundAmount, reason: refundReason }),
    onSuccess: () => { setRefundFor(null); qc.invalidateQueries({ queryKey: ['payments'] }); },
    onError: (e: unknown) => setError(errMsg(e, 'Refund failed')),
  });

  return (
    <>
      <PageHeader title="Payments" subtitle="Transactions, reconciliation and refunds" />
      <ErrorBanner message={error} />

      {recon && (recon.pending.length > 0 || recon.processing.length > 0) && (
        <Card className="mb-6 border-amber-200 bg-amber-50/50">
          <h2 className="mb-2 text-sm font-semibold text-amber-900">
            {recon.pending.length} cash payment{recon.pending.length === 1 ? '' : 's'} awaiting confirmation
          </h2>
          <div className="space-y-1 text-sm">
            {recon.pending.slice(0, 5).map((p) => (
              <div key={p.id} className="flex items-center justify-between rounded bg-white px-3 py-2 ring-1 ring-amber-100">
                <span>{p.booking_reference} — {p.currency} {p.amount.toFixed(2)}</span>
                <Button variant="secondary" onClick={() => confirm.mutate(p.id)}>Confirm cash</Button>
              </div>
            ))}
          </div>
        </Card>
      )}

      <FilterBar>
        <select className="rounded-lg border-0 bg-white px-3 py-2 text-sm ring-1 ring-slate-300" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} aria-label="Filter by status">
          <option value="">All</option>
          {['pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded', 'expired'].map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
        </select>
        <a href="#" onClick={(e) => { e.preventDefault(); window.open(`${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8891/api/v1'}/payments-export`, '_blank'); }} className="text-sm font-medium text-teal-700 hover:underline">Export CSV</a>
      </FilterBar>

      {isLoading ? <Spinner /> : (
        <>
          <DataTable
            head={['Booking', 'Method', 'Amount', 'Status', 'Reference', 'Created', 'Actions']}
            rows={(data?.data ?? []).map((p) => [
              <span key="b" className="font-mono">{p.booking_reference}</span>,
              p.method,
              `${p.currency} ${p.amount.toFixed(2)}`,
              <Badge key="s" tone={statusTone(p.status)}>{p.status.replace('_', ' ')}</Badge>,
              <span key="r" className="text-xs text-slate-400">{p.provider_ref ?? '—'}</span>,
              new Date(p.created_at).toLocaleDateString(),
              <div key="a" className="flex gap-2">
                {p.method === 'cash' && p.status === 'pending' && (
                  <button className="text-sm font-medium text-teal-700 hover:underline" onClick={() => confirm.mutate(p.id)}>Confirm</button>
                )}
                {['paid', 'partially_refunded'].includes(p.status) && (
                  <button className="text-sm font-medium text-red-600 hover:underline" onClick={() => { setRefundFor(p); setRefundAmount(String(p.amount)); }}>Refund</button>
                )}
              </div>,
            ])}
          />
          <Pagination meta={data?.meta} onPage={setPage} />
        </>
      )}

      {refundFor && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-slate-900/40" onClick={() => setRefundFor(null)} />
          <Card className="relative w-full max-w-sm">
            <h3 className="font-semibold">Refund {refundFor.booking_reference}</h3>
            <div className="mt-4 space-y-3">
              <label className="block text-sm">Amount ({refundFor.currency})
                <input type="number" step="0.01" min="0.01" max={refundFor.amount} className="mt-1 w-full rounded-lg border-0 px-3 py-2 ring-1 ring-slate-300" value={refundAmount} onChange={(e) => setRefundAmount(e.target.value)} />
              </label>
              <label className="block text-sm">Reason
                <input className="mt-1 w-full rounded-lg border-0 px-3 py-2 ring-1 ring-slate-300" value={refundReason} onChange={(e) => setRefundReason(e.target.value)} />
              </label>
              <div className="flex gap-2">
                <Button variant="danger" onClick={() => refund.mutate()} disabled={refund.isPending || !refundReason}>Process refund</Button>
                <Button variant="ghost" onClick={() => setRefundFor(null)}>Close</Button>
              </div>
            </div>
          </Card>
        </div>
      )}
    </>
  );
}
