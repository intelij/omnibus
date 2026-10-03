'use client';

import { api, ApiError, setSession, UserProfile } from '@/lib/api';
import { Button, Card, ErrorBanner, Input } from '@/components/ui';
import { Bus } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const res = await api.post<{ token: string; user: UserProfile }>('/auth/login', { email, password });
      setSession(res.token, res.user);
      router.push(res.user.is_platform_user ? '/platform' : '/admin');
    } catch (err) {
      setError(err instanceof ApiError ? (err.body.message ?? 'Login failed') : 'Login failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
      <Card className="w-full max-w-sm">
        <div className="mb-6 flex items-center gap-2 text-teal-700">
          <Bus className="h-7 w-7" />
          <span className="text-xl font-bold">Operator sign in</span>
        </div>
        <form onSubmit={submit} className="space-y-4">
          <Input label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoComplete="username" />
          <Input label="Password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required autoComplete="current-password" />
          <ErrorBanner message={error} />
          <Button type="submit" className="w-full" disabled={loading}>{loading ? 'Signing in…' : 'Sign in'}</Button>
        </form>
      </Card>
    </div>
  );
}
