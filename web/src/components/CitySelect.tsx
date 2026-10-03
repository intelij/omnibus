'use client';

import { api } from '@/lib/api';
import { clsx } from 'clsx';
import { Check, ChevronDown, Search, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

interface LocationOption {
  id: number;
  name: string;
  type?: string;
  province?: string;
}

export interface CityOption {
  name: string;
  province?: string;
  disabled?: boolean;
  note?: string;
}

/**
 * Accessible searchable city picker (combobox pattern).
 * - Filters server-side via /public/locations when `source='locations'`,
 *   or client-side over a fixed `options` list (route-serviced endpoints).
 * - Keyboard: ↓/↑ navigate, Enter select, Esc close, typing searches.
 * - Selected state is explicit (chip + check icon), clearable via ✕.
 */
export function CitySelect({
  label,
  value,
  onChange,
  options,
  exclude,
  placeholder = 'Search city…',
  required,
  id,
}: {
  label: React.ReactNode;
  value: string;
  onChange: (v: string) => void;
  /** Fixed option list (e.g. serviced endpoints) — if omitted, queries the locations API. */
  options?: CityOption[];
  /** City name to exclude (prevents same origin/destination). */
  exclude?: string;
  placeholder?: string;
  required?: boolean;
  id?: string;
}) {
  const listId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(-1);
  const [remote, setRemote] = useState<LocationOption[]>([]);

  // Server-side search for large datasets; debounced.
  useEffect(() => {
    if (options || !query) return;
    const t = setTimeout(() => {
      api.get<{ data: LocationOption[] }>(`/public/locations?q=${encodeURIComponent(query)}&limit=15`)
        .then((r) => setRemote(r.data))
        .catch(() => setRemote([]));
    }, 200);
    return () => clearTimeout(t);
  }, [query, options]);

  const items: CityOption[] = options
    ? options.filter((o) => o.name.toLowerCase().includes(query.toLowerCase()))
    : remote.map((l) => ({ name: l.name, province: l.province }));

  const visible = items.filter((o) => o.name !== exclude);

  const select = (o: CityOption) => {
    if (o.disabled) return;
    onChange(o.name);
    setQuery('');
    setOpen(false);
    setActive(-1);
  };

  const clear = () => {
    onChange('');
    setQuery('');
    inputRef.current?.focus();
  };

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (!open && (e.key === 'ArrowDown' || e.key === 'Enter')) { setOpen(true); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, visible.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); }
    else if (e.key === 'Enter') { e.preventDefault(); if (visible[active]) select(visible[active]); }
    else if (e.key === 'Escape') { setOpen(false); }
  };

  return (
    <div className="relative">
      <span className="mb-1 block text-sm font-medium text-ink-secondary">{label}</span>
      <div className={clsx(
        'flex items-center gap-2 rounded-lg border-0 bg-surface px-3 ring-1 ring-border-strong',
        'focus-within:ring-2 focus-within:ring-brand',
      )}>
        <Search className="h-4 w-4 shrink-0 text-ink-faint" aria-hidden />
        <input
          ref={inputRef}
          id={id}
          role="combobox"
          aria-expanded={open}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-label={typeof label === 'string' ? label : 'City'}
          className="w-full bg-transparent py-2.5 text-sm text-ink outline-none placeholder:text-ink-faint"
          placeholder={value ? '' : placeholder}
          value={open ? query : value}
          required={required && !value}
          onFocus={() => setOpen(true)}
          onBlur={() => setTimeout(() => setOpen(false), 150)}
          onChange={(e) => { setQuery(e.target.value); setOpen(true); setActive(-1); if (!open) onChange(''); if (!e.target.value) setRemote([]); }}
          onKeyDown={onKeyDown}
          autoComplete="off"
        />
        {value && (
          <button type="button" onClick={clear} aria-label={`Clear ${label}`}
            className="rounded p-0.5 text-ink-faint hover:bg-surface-muted hover:text-ink">
            <X className="h-3.5 w-3.5" aria-hidden />
          </button>
        )}
        <ChevronDown className="h-4 w-4 shrink-0 text-ink-faint" aria-hidden />
      </div>

      {value && !open && (
        <p className="mt-1 flex items-center gap-1 text-xs text-ink-faint">
          <Check className="h-3 w-3 text-emerald-600" aria-hidden /> {value}
        </p>
      )}

      {open && (
        <ul id={listId} role="listbox" aria-label={`${label} options`}
          className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg bg-surface py-1 text-sm shadow-lg ring-1 ring-border">
          {visible.length === 0 && (
            <li className="px-3 py-2 text-ink-faint" role="option" aria-selected={false} aria-disabled>
              {query ? `No cities match “${query}”` : 'Type to search cities'}
            </li>
          )}
          {visible.map((o, i) => (
            <li
              key={o.name}
              role="option"
              aria-selected={o.name === value}
              aria-disabled={o.disabled}
              onMouseDown={(e) => { e.preventDefault(); select(o); }}
              onMouseEnter={() => setActive(i)}
              className={clsx(
                'flex cursor-pointer items-center justify-between px-3 py-2',
                i === active && 'bg-brand-soft',
                o.name === value && 'font-semibold text-brand',
                o.disabled && 'cursor-not-allowed text-ink-faint',
              )}
            >
              <span>{o.name}{o.province && <span className="ml-1 text-xs text-ink-faint">{o.province}</span>}</span>
              {o.disabled
                ? <span className="text-xs">{o.note ?? 'No service'}</span>
                : o.name === value && <Check className="h-4 w-4" aria-hidden />}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
