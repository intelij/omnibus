'use client';

import { useQuery } from '@tanstack/react-query';
import { api, TenantInfo } from '@/lib/api';
import { readableOnWhite } from '@/lib/brand';
import { Bus, LifeBuoy } from 'lucide-react';
import Link from 'next/link';

export function useTenant() {
  return useQuery({
    queryKey: ['tenant'],
    queryFn: () => api.get<TenantInfo>('/public/tenant'),
    staleTime: 5 * 60_000,
  });
}

/**
 * Tenant brand colour, guaranteed readable as text/accents on white.
 * Returns the raw colour too for decorative use (borders).
 */
export function useBrand() {
  const { data: tenant } = useTenant();
  const raw = tenant?.branding?.primary_color ?? '#0f766e';
  const rawSecondary = tenant?.branding?.secondary_color ?? '#134e4a';
  return {
    tenant,
    primary: readableOnWhite(raw),
    secondary: readableOnWhite(rawSecondary),
    rawPrimary: raw,
    rawSecondary,
  };
}

export function PublicShell({ children }: { children?: React.ReactNode }) {
  const { tenant, primary, rawPrimary } = useBrand();

  return (
    <div className="min-h-screen">
      <a href="#main" className="skip-link">Skip to content</a>
      <header className="border-b border-border bg-surface" style={{ borderTopColor: rawPrimary, borderTopWidth: 4 }}>
        <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
          <Link href="/" className="flex items-center gap-2 text-lg font-bold" style={{ color: primary }}>
            <Bus className="h-6 w-6" aria-hidden />
            {tenant?.name ?? 'TransitHub'}
          </Link>
          <nav className="flex items-center gap-4 text-sm">
            <Link href="/track" className="text-ink-muted hover:text-ink">
              Manage booking
            </Link>
            <a href="mailto:support@example.com" className="flex items-center gap-1 text-ink-muted hover:text-ink">
              <LifeBuoy className="h-4 w-4" aria-hidden /> Help
            </a>
          </nav>
        </div>
      </header>
      <main id="main" className="mx-auto max-w-5xl px-4 py-8">{children}</main>
      <footer className="border-t border-border py-6 text-center text-xs text-ink-faint">
        {tenant?.branding?.footer_text ?? `Powered by ${tenant?.name ?? 'TransitHub'}`}
      </footer>
    </div>
  );
}
