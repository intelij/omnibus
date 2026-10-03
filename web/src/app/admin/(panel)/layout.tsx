'use client';

import { api, clearSession, getToken, getUser, UserProfile } from '@/lib/api';
import { readableOnWhite } from '@/lib/brand';
import { clsx } from 'clsx';
import {
  Armchair, BarChart3, Banknote, Bus, CalendarClock, ClipboardList,
  LifeBuoy, LogOut, Map, ScanLine, Settings, Users,
} from 'lucide-react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';

const NAV = [
  { href: '/admin', label: 'Dashboard', icon: BarChart3, perm: null },
  { href: '/admin/trips', label: 'Trips', icon: CalendarClock, perm: 'trips.view' },
  { href: '/admin/bookings', label: 'Bookings', icon: ClipboardList, perm: 'bookings.view' },
  { href: '/admin/verify', label: 'Verify ticket', icon: ScanLine, perm: 'tickets.verify' },
  { href: '/admin/payments', label: 'Payments', icon: Banknote, perm: 'payments.view' },
  { href: '/admin/buses', label: 'Fleet', icon: Bus, perm: 'buses.view' },
  { href: '/admin/seat-layouts', label: 'Seat layouts', icon: Armchair, perm: 'seat_layouts.view' },
  { href: '/admin/routes', label: 'Routes', icon: Map, perm: 'routes.view' },
  { href: '/admin/schedules', label: 'Schedules', icon: CalendarClock, perm: 'schedules.view' },
  { href: '/admin/promotions', label: 'Promotions', icon: BarChart3, perm: 'promotions.view' },
  { href: '/admin/support', label: 'Support', icon: LifeBuoy, perm: 'support.view' },
  { href: '/admin/reports', label: 'Reports', icon: BarChart3, perm: 'reports.view' },
  { href: '/admin/users', label: 'Team', icon: Users, perm: 'users.view' },
  { href: '/admin/settings', label: 'Settings', icon: Settings, perm: 'settings.view' },
];

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [user] = useState<UserProfile | null>(() => getUser());
  const [ready, setReady] = useState(false);

  useEffect(() => {
    if (!getToken()) {
      router.replace('/admin/login');
      return;
    }
    if (user?.is_platform_user) {
      router.replace('/platform');
      return;
    }
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setReady(true);
  }, [router, user]);

  if (!ready) return null;

  const primary = readableOnWhite(user?.tenant?.branding?.primary_color ?? '#0f766e');
  const items = NAV.filter((i) => !i.perm || user?.permissions.includes(i.perm) || user?.roles.includes('tenant_owner'));

  const logout = async () => {
    await api.post('/auth/logout').catch(() => undefined);
    clearSession();
    router.push('/admin/login');
  };

  return (
    <div className="flex min-h-screen">
      <a href="#main" className="skip-link">Skip to content</a>
      <aside className="flex w-60 flex-col border-r border-border bg-surface">
        <div className="flex items-center gap-2 border-b border-border px-4 py-4 font-bold" style={{ color: primary }}>
          <Bus className="h-5 w-5" /> {user?.tenant?.name ?? 'Admin'}
        </div>
        <nav className="flex-1 space-y-0.5 overflow-y-auto p-2">
          {items.map(({ href, label, icon: Icon }) => (
            <Link
              key={href}
              href={href}
              className={clsx(
                'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm',
                pathname === href ? 'bg-brand-soft font-semibold text-brand-ink' : 'text-ink-muted hover:bg-surface-muted',
              )}
            >
              <Icon className="h-4 w-4" aria-hidden /> {label}
            </Link>
          ))}
        </nav>
        <div className="border-t border-border p-3">
          <p className="truncate text-sm font-medium">{user?.name}</p>
          <p className="truncate text-xs text-ink-faint">{user?.roles.join(', ')}</p>
          <button onClick={logout} className="mt-2 flex items-center gap-1 text-xs text-ink-faint hover:text-[var(--danger)]">
            <LogOut className="h-3.5 w-3.5" /> Sign out
          </button>
        </div>
      </aside>
      <main id="main" className="flex-1 overflow-x-auto p-6">{children}</main>
    </div>
  );
}
