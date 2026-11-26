'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { DashboardShell } from '@/components/DashboardShell';
import { ResponsiveTable } from '@/components/ResponsiveTable';
import { StatusBadge } from '@/components/StatusBadge';
import { apiRequest } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import type { AttendanceRecord, AttendanceSummary, Branch, PaginatedResponse } from '@/types/api';

function todayIsoDate(): string {
  return new Date().toISOString().slice(0, 10);
}

export default function DashboardOverviewPage() {
  const { token, activeTenantId } = useAuth();
  const [date, setDate] = useState('');
  const [branchId, setBranchId] = useState<string>('');
  const [branches, setBranches] = useState<Branch[]>([]);
  const [summary, setSummary] = useState<AttendanceSummary | null>(null);
  const [records, setRecords] = useState<AttendanceRecord[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void Promise.resolve().then(() => setDate((prev) => prev || todayIsoDate()));
  }, []);

  const loadData = useCallback(async () => {
    if (!token || !activeTenantId || !date) return;
    const branchQuery = branchId ? `&branch_id=${branchId}` : '';

    try {
      const [branchRes, summaryRes, attRes] = await Promise.all([
        apiRequest<PaginatedResponse<Branch>>('/branches?per_page=100', {
          token,
          tenantId: activeTenantId,
        }),
        apiRequest<{ data: AttendanceSummary }>(
          `/reports/attendance-summary?from_date=${date}&to_date=${date}${branchQuery}`,
          { token, tenantId: activeTenantId },
        ),
        apiRequest<PaginatedResponse<AttendanceRecord>>(
          `/attendance?work_date=${date}${branchQuery}&per_page=15`,
          { token, tenantId: activeTenantId },
        ),
      ]);

      setError(null);
      setBranches(branchRes.data);
      setSummary(summaryRes.data);
      setRecords(attRes.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error cargando estadísticas.');
    }
  }, [token, activeTenantId, date, branchId]);

  useEffect(() => {
    void Promise.resolve().then(() => loadData());
  }, [loadData]);

  const stats = summary?.attendance;

  return (
    <DashboardShell>
      <div className="space-y-6">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-slate-900">Resumen Diario de Asistencia</h1>
            <p className="text-sm text-slate-500">
              Indicadores operativos por sucursal e incidencias pendientes.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <div>
              <label htmlFor="filter-date" className="block text-xs font-medium text-slate-600">
                Fecha
              </label>
              <input
                id="filter-date"
                type="date"
                value={date}
                onChange={(e) => setDate(e.target.value)}
                className="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm"
              />
            </div>

            <div>
              <label htmlFor="filter-branch" className="block text-xs font-medium text-slate-600">
                Sucursal
              </label>
              <select
                id="filter-branch"
                value={branchId}
                onChange={(e) => setBranchId(e.target.value)}
                className="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm"
              >
                <option value="">Todas</option>
                {branches.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                  </option>
                ))}
              </select>
            </div>
          </div>
        </div>

        {error && (
          <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
            {error}
          </div>
        )}

        <div className="grid grid-cols-2 gap-4 lg:grid-cols-6">
          <KpiCard label="Registros" value={stats?.total_records ?? 0} />
          <KpiCard label="A tiempo" value={stats?.on_time_count ?? 0} tone="emerald" />
          <KpiCard label="Retardos" value={stats?.late_count ?? 0} tone="amber" />
          <KpiCard label="Turnos abiertos" value={stats?.open_shifts ?? 0} />
          <KpiCard
            label="Incidencias pendientes"
            value={summary?.incidents.pending_count ?? 0}
            tone="rose"
          />
          <KpiCard
            label="Horas trabajadas"
            value={Math.round(((stats?.total_worked_minutes ?? 0) / 60) * 10) / 10}
          />
        </div>

        {summary && Object.keys(summary.incidents.by_type).length > 0 && (
          <section className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="mb-3 text-sm font-semibold text-slate-800">Desglose de incidencias</h2>
            <div className="flex flex-wrap gap-3">
              {Object.entries(summary.incidents.by_type).map(([type, count]) => (
                <div
                  key={type}
                  className="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm"
                >
                  <StatusBadge value={type} />
                  <span className="font-semibold text-slate-900">{count}</span>
                </div>
              ))}
            </div>
          </section>
        )}

        <section className="space-y-3">
          <h2 className="text-base font-semibold text-slate-900">Asistencias del día</h2>
          <ResponsiveTable
            caption="Registros recientes de asistencia"
            headers={['Empleado', 'Sucursal', 'Estado', 'Entrada (UTC)', 'Salida (UTC)', 'Retardo (min)']}
          >
            {records.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-slate-500">
                  Sin registros de asistencia para los filtros seleccionados.
                </td>
              </tr>
            ) : (
              records.map((rec) => (
                <tr key={rec.id} className="hover:bg-slate-50">
                  <td data-label="Empleado" className="px-4 py-3 font-medium text-slate-900">
                    {rec.employee ? `${rec.employee.full_name} (${rec.employee.employee_code})` : `#${rec.employee_id}`}
                  </td>
                  <td data-label="Sucursal" className="px-4 py-3">
                    {rec.branch?.name ?? '—'}
                  </td>
                  <td data-label="Estado" className="px-4 py-3">
                    <StatusBadge value={rec.status} />
                  </td>
                  <td data-label="Entrada (UTC)" className="px-4 py-3 font-mono text-xs">
                    {new Date(rec.clock_in_at).toLocaleString('es-MX')}
                  </td>
                  <td data-label="Salida (UTC)" className="px-4 py-3 font-mono text-xs">
                    {rec.clock_out_at ? new Date(rec.clock_out_at).toLocaleString('es-MX') : '—'}
                  </td>
                  <td data-label="Retardo (min)" className="px-4 py-3">
                    {rec.late_minutes}
                  </td>
                </tr>
              ))
            )}
          </ResponsiveTable>
        </section>
      </div>
    </DashboardShell>
  );
}

function KpiCard({
  label,
  value,
  tone = 'slate',
}: {
  label: string;
  value: number;
  tone?: 'slate' | 'emerald' | 'amber' | 'rose';
}) {
  const accent = {
    slate: 'text-slate-900',
    emerald: 'text-emerald-700',
    amber: 'text-amber-700',
    rose: 'text-rose-700',
  }[tone];

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className={`mt-1 text-2xl font-bold ${accent}`}>{value}</p>
    </div>
  );
}
