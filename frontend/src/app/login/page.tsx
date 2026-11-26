'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Eye, EyeOff } from 'lucide-react';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';

export default function LoginPage() {
  const { login } = useAuth();
  const router = useRouter();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await login(email, password);
      router.push('/');
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError('No fue posible conectar con el servidor.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
      <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <div className="mb-6 flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-600 text-sm font-bold text-white">
            CW
          </div>
          <div>
            <h1 className="text-xl font-bold text-slate-900">CheckIn WA</h1>
            <p className="text-xs text-slate-500">Acceso al panel de Recursos Humanos</p>
          </div>
        </div>

        {error && (
          <div
            role="alert"
            className="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-sm text-rose-700"
          >
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label htmlFor="email" className="block text-sm font-medium text-slate-700">
              Correo electrónico
            </label>
            <span id="email-hint" className="mt-0.5 block text-xs text-slate-500">
              Formato: usuario@empresa.com
            </span>
            <input
              id="email"
              name="email"
              type="email"
              required
              autoComplete="username"
              spellCheck={false}
              enterKeyHint="next"
              aria-describedby="email-hint"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="field-input mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-emerald-600 focus:outline-none"
              placeholder="owner@acme.local"
            />
            <p className="field-error mt-1 text-xs text-rose-600">
              Ingresa un correo electrónico válido.
            </p>
          </div>

          <div>
            <label htmlFor="current-password" className="block text-sm font-medium text-slate-700">
              Contraseña
            </label>
            <div className="relative mt-1">
              <input
                id="current-password"
                name="password"
                type={showPassword ? 'text' : 'password'}
                required
                autoComplete="current-password"
                enterKeyHint="done"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="field-input block w-full rounded-lg border border-slate-300 px-3 py-2 pr-10 text-sm text-slate-900 focus:border-emerald-600 focus:outline-none"
              />
              <button
                type="button"
                onClick={() => setShowPassword((prev) => !prev)}
                aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                className="absolute inset-y-0 right-0 flex items-center px-3 text-slate-500 hover:text-slate-800"
              >
                {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
              </button>
            </div>
            <p className="field-error mt-1 text-xs text-rose-600">La contraseña es obligatoria.</p>
          </div>

          <button
            type="submit"
            disabled={submitting}
            className="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
          >
            {submitting ? 'Ingresando...' : 'Iniciar sesión'}
          </button>
        </form>
      </div>
    </main>
  );
}
