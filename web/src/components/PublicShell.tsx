'use client';

import { useQuery } from '@tanstack/react-query';
import { api, TenantInfo } from '@/lib/api';
import { Bus, LifeBuoy } from 'lucide-react';
import Link from 'next/link';

export function useTenant() {
  return useQuery({
    queryKey: ['tenant'],
    queryFn: () => api.get<TenantInfo>('/public/tenant'),
    staleTime: 5 * 60_000,
  });
}

export function PublicShell({ children }: { children?: React.ReactNode }) {
  const { data: tenant } = useTenant();
  const primary = tenant?.branding?.primary_color ?? '#0f766e';

  return (
    <div className="min-h-screen">
      <header className="border-b border-slate-200 bg-white" style={{ borderTopColor: primary, borderTopWidth: 4 }}>
        <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
          <Link href="/" className="flex items-center gap-2 text-lg font-bold" style={{ color: primary }}>
            <Bus className="h-6 w-6" aria-hidden />
            {tenant?.name ?? 'TransitHub'}
          </Link>
          <nav className="flex items-center gap-4 text-sm">
            <Link href="/track" className="text-slate-600 hover:text-slate-900">
              Manage booking
            </Link>
            <a href="mailto:support@example.com" className="flex items-center gap-1 text-slate-600 hover:text-slate-900">
              <LifeBuoy className="h-4 w-4" aria-hidden /> Help
            </a>
          </nav>
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-4 py-8">{children}</main>
      <footer className="border-t border-slate-200 py-6 text-center text-xs text-slate-400">
        {tenant?.branding?.footer_text ?? `Powered by ${tenant?.name ?? 'TransitHub'}`}
      </footer>
    </div>
  );
}
