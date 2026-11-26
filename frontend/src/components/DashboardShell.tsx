'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import {
  AlertTriangle,
  Building2,
  CalendarClock,
  Clock,
  FileSpreadsheet,
  LayoutDashboard,
  LogOut,
  Menu,
  MessageSquare,
  Users,
  X,
} from 'lucide-react';
import { useAuth } from '@/lib/auth-context';

const NAV_ITEMS = [
  { href: '/', label: 'Resumen', icon: LayoutDashboard },
  { href: '/attendance', label: 'Asistencias', icon: Clock },
  { href: '/incidents', label: 'Incidencias y Permisos', icon: AlertTriangle },
  { href: '/employees', label: 'Empleados', icon: Users },
  { href: '/branches', label: 'Sucursales', icon: Building2 },
  { href: '/schedules', label: 'Horarios', icon: CalendarClock },
  { href: '/channels', label: 'WhatsApp', icon: MessageSquare },
  { href: '/reports', label: 'Reportes', icon: FileSpreadsheet },
];

export function DashboardShell({ children }: { children: React.ReactNode }) {
  const { user, token, tenants, activeTenantId, activeMembership, loading, logout, selectTenant } =
    useAuth();
  const pathname = usePathname();
  const router = useRouter();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  useEffect(() => {
    if (!loading && !token) {
      router.replace('/login');
    }
  }, [loading, token, router]);

  if (loading || !token || !user) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50 text-sm text-slate-600">
        Cargando sesión de CheckIn WA...
      </div>
    );
  }

  return (
    <div className="flex min-h-screen flex-col bg-slate-50 md:flex-row">
      {/* Sidebar desktop */}
      <aside className="hidden w-64 shrink-0 flex-col border-r border-slate-200 bg-white md:flex">
        <div className="flex h-16 items-center gap-2.5 border-b border-slate-200 px-5">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-600 font-bold text-white">
            CW
          </div>
          <div>
            <p className="text-sm font-bold text-slate-900">CheckIn WA</p>
            <p className="text-xs text-slate-500">Control de Asistencia</p>
          </div>
        </div>

        <nav aria-label="Navegación principal" className="flex-1 space-y-1 p-3">
          {NAV_ITEMS.map((item) => {
            const Icon = item.icon;
            const active = pathname === item.href;
            return (
              <Link
                key={item.href}
                href={item.href}
                aria-current={active ? 'page' : undefined}
                className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ${
                  active
                    ? 'bg-emerald-50 text-emerald-800'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                }`}
              >
                <Icon className="h-4 w-4 shrink-0" />
                <span>{item.label}</span>
              </Link>
            );
          })}
        </nav>

        <div className="border-t border-slate-200 p-4 text-xs text-slate-600">
          <p className="font-semibold text-slate-900 truncate">{user.name}</p>
          <p className="truncate">{user.email}</p>
          {activeMembership && (
            <p className="mt-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-700">
              Rol: {activeMembership.role}
            </p>
          )}
        </div>
      </aside>

      {/* Main area */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-16 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 md:px-6">
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => setMobileMenuOpen((v) => !v)}
              aria-label="Abrir menú"
              aria-expanded={mobileMenuOpen}
              className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 md:hidden"
            >
              {mobileMenuOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
            </button>

            <label htmlFor="tenant-switcher" className="text-xs font-medium text-slate-500">
              Empresa:
            </label>
            <select
              id="tenant-switcher"
              value={activeTenantId ?? ''}
              onChange={(e) => selectTenant(Number(e.target.value))}
              className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-800"
            >
              {tenants.map((tenant) => (
                <option key={tenant.id} value={tenant.id}>
                  {tenant.name} ({tenant.code})
                </option>
              ))}
            </select>
          </div>

          <button
            type="button"
            onClick={() => void logout().then(() => router.push('/login'))}
            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100"
          >
            <LogOut className="h-3.5 w-3.5" />
            <span>Cerrar sesión</span>
          </button>
        </header>

        {mobileMenuOpen && (
          <nav aria-label="Menú móvil" className="border-b border-slate-200 bg-white p-3 md:hidden">
            <div className="grid grid-cols-2 gap-1.5">
              {NAV_ITEMS.map((item) => {
                const Icon = item.icon;
                const active = pathname === item.href;
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    onClick={() => setMobileMenuOpen(false)}
                    aria-current={active ? 'page' : undefined}
                    className={`flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium ${
                      active ? 'bg-emerald-50 text-emerald-800' : 'text-slate-700 hover:bg-slate-100'
                    }`}
                  >
                    <Icon className="h-4 w-4" />
                    <span>{item.label}</span>
                  </Link>
                );
              })}
            </div>
          </nav>
        )}

        <main className="flex-1 p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}
