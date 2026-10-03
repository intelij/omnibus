const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8891/api/v1';
const TENANT = process.env.NEXT_PUBLIC_TENANT ?? 'demo-transport';

export class ApiError extends Error {
  constructor(
    public status: number,
    public body: { message?: string; errors?: Record<string, string[]> },
  ) {
    super(body.message ?? `Request failed (${status})`);
  }
}

type Options = RequestInit & { token?: string | null; public?: boolean };

async function request<T>(path: string, opts: Options = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Tenant': TENANT,
    ...(opts.headers as Record<string, string>),
  };
  if (opts.body && typeof opts.body === 'string') headers['Content-Type'] = 'application/json';
  const token = opts.token ?? getToken();
  if (token) headers.Authorization = `Bearer ${token}`;

  const res = await fetch(`${API_URL}${path}`, { ...opts, headers });
  const body = res.status === 204 ? {} : await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, body);
  return body as T;
}

export const api = {
  get: <T>(path: string, opts?: Options) => request<T>(path, opts),
  post: <T>(path: string, data?: unknown, opts?: Options) =>
    request<T>(path, { ...opts, method: 'POST', body: data ? JSON.stringify(data) : undefined }),
  patch: <T>(path: string, data?: unknown, opts?: Options) =>
    request<T>(path, { ...opts, method: 'PATCH', body: data ? JSON.stringify(data) : undefined }),
  del: <T>(path: string, opts?: Options) => request<T>(path, { ...opts, method: 'DELETE' }),
};

// ── Auth token storage (staff sessions) ─────────────────────────
const TOKEN_KEY = 'transithub_token';
const USER_KEY = 'transithub_user';

export function getToken(): string | null {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem(TOKEN_KEY);
}
export function getUser<T = UserProfile>(): T | null {
  if (typeof window === 'undefined') return null;
  const raw = localStorage.getItem(USER_KEY);
  return raw ? (JSON.parse(raw) as T) : null;
}
export function setSession(token: string, user: UserProfile) {
  localStorage.setItem(TOKEN_KEY, token);
  localStorage.setItem(USER_KEY, JSON.stringify(user));
}
export function clearSession() {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}

export function newIdempotencyKey() {
  return crypto.randomUUID();
}

// ── Types ────────────────────────────────────────────────────────
export interface UserProfile {
  id: number;
  name: string;
  email: string;
  tenant_id: number | null;
  is_platform_user: boolean;
  roles: string[];
  permissions: string[];
  tenant: { slug: string; name: string; currency: string; timezone: string; branding: Record<string, string> } | null;
}

export interface Stop { id: number; name: string; sequence: number; can_board: boolean; can_alight: boolean; offset_minutes: number }

export interface Trip {
  id: number;
  status: string;
  departure_at: string;
  arrival_estimate_at: string | null;
  base_fare: number;
  currency?: string;
  route?: { id: number; name: string; origin: string; destination: string; duration_minutes: number | null; stops: Stop[] | null };
  bus?: { id: number; registration: string; type: string; amenities: string[] | null; decks: number } | null;
}

export interface SearchResult {
  trip: Trip;
  boarding_stop: { id: number; name: string; sequence: number };
  dropoff_stop: { id: number; name: string; sequence: number };
  available_seats: number;
  fare: number;
  bookable: boolean;
}

export interface SeatCell {
  id: number;
  deck: number;
  row: number;
  col: number;
  label: string;
  type: string;
  position: string;
  price_modifier: number;
  state: 'available' | 'held' | 'booked' | 'unavailable';
}

export interface Booking {
  id: number;
  reference: string;
  status: string;
  currency: string;
  subtotal: number;
  discount: number;
  total: number;
  contact: { name: string; email: string | null; phone: string };
  boarding_sequence: number;
  dropoff_sequence: number;
  boarding?: { id: number; name: string };
  dropoff?: { id: number; name: string };
  trip?: Trip;
  seats?: { label: string; fare: number; status: string }[];
  passengers?: { id: number; name: string; type: string }[];
  events?: { id: number; event: string; actor_type: string | null; created_at: string }[];
  promotion_code?: string | null;
  payment?: {
    id: number; method: string; status: string; instructions: string | null;
    redirect_url?: string | null; expires_at?: string | null;
  } | null;
  expires_at?: string;
  confirmed_at?: string;
  created_at: string;
}

export interface Ticket {
  id: number;
  passenger_name: string;
  seat_label: string;
  status: string;
  qr: string;
  issued_at: string;
}

export interface TenantInfo {
  name: string;
  legal_name: string;
  currency: string;
  timezone: string;
  branding: Record<string, string>;
  payment_methods: { key: string; label: string; available: boolean }[];
  rules: Record<string, unknown>;
}

export interface Paginated<T> {
  data: T[];
  meta?: { current_page: number; last_page: number; total: number };
}

export function errMsg(e: unknown, fallback: string): string {
  return e instanceof ApiError ? (e.body.message ?? fallback) : fallback;
}
