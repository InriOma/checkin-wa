'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { Download } from 'lucide-react';
import { DashboardShell } from '@/components/DashboardShell';
import { apiRequest, downloadAuthenticatedCsv } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import type { AttendanceSummary, Branch, PaginatedResponse } from '@/types/api';

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

export default function ReportsPage() {
  const { token, activeTenantId } = useAuth();
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [branchId, setBranchId] = useState('');
  const [branches, setBranches] = useState<Branch[]>([]);
  const [summary, setSummary] = useState<AttendanceSummary | null>(null);
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void Promise.resolve().then(() => {
      const today = todayIso();
      setFromDate((prev) => prev || today);
      setToDate((prev) => prev || today);
    });
  }, []);

  const loadSummary = useCallback(async () => {
    if (!token || !activeTenantId || !fromDate || !toDate) return;
    const params = new URLSearchParams({ from_date: fromDate, to_date: toDate });
    if (branchId) params.set('branch_id', branchId);

    try {
      const [brRes, sumRes] = await Promise.all([
        apiRequest<PaginatedResponse<Branch>>('/branches?per_page=100', {
          token,
          tenantId: activeTenantId,
        }),
        apiRequest<{ data: AttendanceSummary }>(`/reports/attendance-summary?${params.toString()}`, {
          token,
          tenantId: activeTenantId,
        }),
      ]);
      setError(null);
      setBranches(brRes.data);
      setSummary(sumRes.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error cargando reporte.');
    }
  }, [token, activeTenantId, fromDate, toDate, branchId]);

  useEffect(() => {
    void Promise.resolve().then(() => loadSummary());
  }, [loadSummary]);

  const handleExport = async () => {
    if (!token || !activeTenantId) return;
    setDownloading(true);
    setError(null);
    try {
      const params = new URLSearchParams({ from_date: fromDate, to_date: toDate });
      if (branchId) params.set('branch_id', branchId);
      await downloadAuthenticatedCsv(
        `/reports/attendance-export?${params.toString()}`,
        `asistencias-${fromDate}-${toDate}.csv`,
        token,
        activeTenantId,
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error exportando CSV.');
    } finally {
      setDownloading(false);
    }
  };

  return (
    <DashboardShell>
      <div className="space-y-6">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-slate-900">Reportes y Exportación</h1>
            <p className="text-sm text-slate-500">
              Genera resúmenes por periodo y descarga CSV compatible con Excel para RRHH.
            </p>
          </div>
          <button
            type="button"
            disabled={downloading}
            onClick={() => void handleExport()}
            className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
          >
            <Download className="h-4 w-4" />
            <span>{downloading ? 'Exportando...' : 'Exportar CSV'}</span>
          </button>
        </div>

        <div className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
          <div>
            <label htmlFor="rep-from" className="block text-xs font-medium text-slate-600">
              Desde
            </label>
            <input
              id="rep-from"
              type="date"
              value={fromDate}
              onChange={(e) => setFromDate(e.target.value)}
              className="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
            />
          </div>
          <div>
            <label htmlFor="rep-to" className="block text-xs font-medium text-slate-600">
              Hasta
            </label>
            <input
              id="rep-to"
              type="date"
              value={toDate}
              onChange={(e) => setToDate(e.target.value)}
              className="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
            />
          </div>
          <div>
            <label htmlFor="rep-branch" className="block text-xs font-medium text-slate-600">
              Sucursal
            </label>
            <select
              id="rep-branch"
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

        {error && (
          <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
            {error}
          </div>
        )}

        {summary && (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div className="rounded-xl border border-slate-200 bg-white p-5">
              <h2 className="text-sm font-semibold text-slate-900">Métricas de asistencia</h2>
              <dl className="mt-3 space-y-2 text-sm">
                <div className="flex justify-between">
                  <dt className="text-slate-500">Total de registros</dt>
                  <dd className="font-semibold">{summary.attendance.total_records}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-slate-500">Puntuales</dt>
                  <dd className="font-semibold text-emerald-700">{summary.attendance.on_time_count}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-slate-500">Con retardo</dt>
                  <dd className="font-semibold text-amber-700">{summary.attendance.late_count}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-slate-500">Minutos totales de retardo</dt>
                  <dd className="font-semibold">{summary.attendance.total_late_minutes} min</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-slate-500">Horas netas trabajadas</dt>
                  <dd className="font-semibold">
                    {Math.round((summary.attendance.total_worked_minutes / 60) * 10) / 10} h
                  </dd>
                </div>
              </dl>
            </div>

            <div className="rounded-xl border border-slate-200 bg-white p-5">
              <h2 className="text-sm font-semibold text-slate-900">Incidencias del periodo</h2>
              <dl className="mt-3 space-y-2 text-sm">
                <div className="flex justify-between">
                  <dt className="text-slate-500">Pendientes de revisión</dt>
                  <dd className="font-semibold text-rose-700">{summary.incidents.pending_count}</dd>
                </div>
                {Object.entries(summary.incidents.by_type).map(([type, count]) => (
                  <div key={type} className="flex justify-between">
                    <dt className="text-slate-500 capitalize">{type.replaceAll('_', ' ')}</dt>
                    <dd className="font-semibold">{count}</dd>
                  </div>
                ))}
              </dl>
            </div>
          </div>
        )}
      </div>
    </DashboardShell>
  );
}
