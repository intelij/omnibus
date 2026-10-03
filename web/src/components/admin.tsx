'use client';

import { clsx } from 'clsx';
import { ReactNode } from 'react';

export function DataTable({
  head,
  rows,
  empty = 'Nothing here yet',
}: {
  head: string[];
  rows: ReactNode[][];
  empty?: string;
}) {
  return (
    <div className="overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            {head.map((h) => <th key={h} className="px-4 py-3 font-medium">{h}</th>)}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.length === 0 ? (
            <tr><td colSpan={head.length} className="px-4 py-10 text-center text-slate-400">{empty}</td></tr>
          ) : rows.map((cells, i) => (
            <tr key={i} className="hover:bg-slate-50">
              {cells.map((c, j) => <td key={j} className="px-4 py-3">{c}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function FilterBar({ children }: { children: ReactNode }) {
  return <div className="mb-4 flex flex-wrap items-center gap-3">{children}</div>;
}

export function Modal({
  open, onClose, title, children,
}: { open: boolean; onClose: () => void; title: string; children: ReactNode }) {
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal aria-label={title}>
      <div className="absolute inset-0 bg-slate-900/40" onClick={onClose} />
      <div className="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold">{title}</h2>
          <button onClick={onClose} className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">✕</button>
        </div>
        {children}
      </div>
    </div>
  );
}

export function Pagination({ meta, onPage }: { meta?: { current_page: number; last_page: number }; onPage: (p: number) => void }) {
  if (!meta || meta.last_page <= 1) return null;
  return (
    <div className="mt-4 flex items-center gap-2 text-sm">
      {Array.from({ length: meta.last_page }, (_, i) => i + 1).map((p) => (
        <button
          key={p}
          onClick={() => onPage(p)}
          className={clsx('h-8 w-8 rounded-lg', p === meta.current_page ? 'bg-teal-700 font-semibold text-white' : 'bg-white ring-1 ring-slate-200 hover:bg-slate-50')}
        >
          {p}
        </button>
      ))}
    </div>
  );
}
