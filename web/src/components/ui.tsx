'use client';

import { clsx } from 'clsx';
import { ReactNode } from 'react';

export function Button({
  children, variant = 'primary', className, ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'ghost' | 'danger' }) {
  return (
    <button
      className={clsx(
        'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-colors',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600',
        'disabled:cursor-not-allowed disabled:opacity-50',
        variant === 'primary' && 'bg-teal-700 text-white hover:bg-teal-800',
        variant === 'secondary' && 'bg-white text-slate-800 ring-1 ring-slate-300 hover:bg-slate-50',
        variant === 'ghost' && 'text-teal-700 hover:bg-teal-50',
        variant === 'danger' && 'bg-red-600 text-white hover:bg-red-700',
        className,
      )}
      {...props}
    >
      {children}
    </button>
  );
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={clsx('rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200', className)}>
      {children}
    </div>
  );
}

export function Input({ label, error, ...props }: React.InputHTMLAttributes<HTMLInputElement> & { label?: ReactNode; error?: string }) {
  return (
    <label className="block">
      {label && <span className="mb-1 block text-sm font-medium text-slate-700">{label}</span>}
      <input
        className={clsx(
          'w-full rounded-lg border-0 bg-white px-3 py-2.5 text-sm ring-1 ring-slate-300',
          'placeholder:text-slate-400 focus:ring-2 focus:ring-teal-600',
          error && 'ring-red-400',
        )}
        aria-invalid={!!error}
        {...props}
      />
      {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}
    </label>
  );
}

export function Select({ label, error, children, ...props }: React.SelectHTMLAttributes<HTMLSelectElement> & { label?: ReactNode; error?: string }) {
  return (
    <label className="block">
      {label && <span className="mb-1 block text-sm font-medium text-slate-700">{label}</span>}
      <select
        className={clsx('w-full rounded-lg border-0 bg-white px-3 py-2.5 text-sm ring-1 ring-slate-300 focus:ring-2 focus:ring-teal-600', error && 'ring-red-400')}
        {...props}
      >
        {children}
      </select>
      {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}
    </label>
  );
}

export function Badge({ children, tone = 'gray' }: { children: ReactNode; tone?: 'gray' | 'green' | 'yellow' | 'red' | 'blue' }) {
  const tones = {
    gray: 'bg-slate-100 text-slate-700',
    green: 'bg-emerald-100 text-emerald-800',
    yellow: 'bg-amber-100 text-amber-800',
    red: 'bg-red-100 text-red-800',
    blue: 'bg-sky-100 text-sky-800',
  };
  return <span className={clsx('inline-flex rounded-full px-2 py-0.5 text-xs font-medium', tones[tone])}>{children}</span>;
}

export function PageHeader({ title, subtitle, children }: { title: string; subtitle?: string; children?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
      </div>
      {children}
    </div>
  );
}

export function EmptyState({ title, hint }: { title: string; hint?: string }) {
  return (
    <div className="rounded-xl border-2 border-dashed border-slate-200 p-10 text-center">
      <p className="font-medium text-slate-600">{title}</p>
      {hint && <p className="mt-1 text-sm text-slate-400">{hint}</p>}
    </div>
  );
}

export function Spinner() {
  return (
    <div className="flex items-center justify-center p-10" role="status" aria-label="Loading">
      <div className="h-8 w-8 animate-spin rounded-full border-2 border-slate-200 border-t-teal-700" />
    </div>
  );
}

export function ErrorBanner({ message }: { message: string | null }) {
  if (!message) return null;
  return <div className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200" role="alert">{message}</div>;
}

export const statusTone = (status: string): 'gray' | 'green' | 'yellow' | 'red' | 'blue' =>
  ({
    confirmed: 'green', paid: 'green', active: 'green', valid: 'green', completed: 'green',
    held: 'yellow', pending: 'yellow', pending_payment: 'yellow', processing: 'yellow', scheduled: 'blue',
    boarding: 'blue', in_transit: 'blue', delayed: 'yellow',
    cancelled: 'red', failed: 'red', expired: 'gray', refunded: 'gray', used: 'gray',
  } as Record<string, 'gray' | 'green' | 'yellow' | 'red' | 'blue'>)[status] ?? 'gray';
