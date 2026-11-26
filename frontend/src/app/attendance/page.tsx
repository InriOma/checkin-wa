'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { Eye, Wrench } from 'lucide-react';
import { DashboardShell } from '@/components/DashboardShell';
import { ModalDialog } from '@/components/ModalDialog';
import { ResponsiveTable } from '@/components/ResponsiveTable';
import { StatusBadge } from '@/components/StatusBadge';
import { ApiError, apiRequest } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import type { AttendanceRecord, PaginatedResponse } from '@/types/api';

export default function AttendancePage() {
  const { token, activeTenantId } = useAuth();
  const [records, setRecords] = useState<AttendanceRecord[]>([]);
  const [statusFilter, setStatusFilter] = useState('');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [error, setError] = useState<string | null>(null);

  const [detail, setDetail] = useState<AttendanceRecord | null>(null);
  const [correcting, setCorrecting] = useState<AttendanceRecord | null>(null);
  const [corrForm, setCorrForm] = useState({
    clock_out_at: '',
    status: 'closed',
    reason: '',
  });
  const [corrError, setCorrError] = useState<string | null>(null);

  const loadRecords = useCallback(async () => {
    if (!token || !activeTenantId) return;
    const params = new URLSearchParams({ per_page: '50' });
    if (statusFilter) params.set('status', statusFilter);
    if (fromDate) params.set('from_date', fromDate);
    if (toDate) params.set('to_date', toDate);

    try {
      const res = await apiRequest<PaginatedResponse<AttendanceRecord>>(
        `/attendance?${params.toString()}`,
        { token, tenantId: activeTenantId },
      );
      setError(null);
      setRecords(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error cargando asistencias.');
    }
  }, [token, activeTenantId, statusFilter, fromDate, toDate]);

  useEffect(() => {
    void Promise.resolve().then(() => loadRecords());
  }, [loadRecords]);

  const openDetail = async (id: number) => {
    if (!token || !activeTenantId) return;
    const res = await apiRequest<{ data: AttendanceRecord }>(`/attendance/${id}`, {
      token,
      tenantId: activeTenantId,
    });
    setDetail(res.data);
  };

  const openCorrect = (rec: AttendanceRecord) => {
    setCorrError(null);
    setCorrForm({
      clock_out_at: rec.clock_out_at ? rec.clock_out_at.slice(0, 16) : '',
      status: 'closed',
      reason: '',
    });
    setCorrecting(rec);
  };

  const handleCorrect = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token || !activeTenantId || !correcting) return;
    setCorrError(null);

    try {
      await apiRequest(`/attendance/${correcting.id}/correct`, {
        method: 'POST',
        body: {
          status: corrForm.status,
          clock_out_at: corrForm.clock_out_at ? new Date(corrForm.clock_out_at).toISOString() : null,
          reason: corrForm.reason.trim(),
        },
        token,
        tenantId: activeTenantId,
      });
      setCorrecting(null);
      await loadRecords();
    } catch (err) {
      setCorrError(err instanceof ApiError ? err.message : 'No se pudo aplicar la corrección.');
    }
  };

  return (
    <DashboardShell>
      <div className="space-y-5">
        <div>
          <h1 className="text-xl font-bold text-slate-900">Monitor de Asistencias</h1>
          <p className="text-sm text-slate-500">
            Consulta entradas, descansos, geocercas y ejecuta correcciones administrativas auditables.
          </p>
        </div>

        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label htmlFor="att-from" className="block text-xs font-medium text-slate-600">
              Desde
            </label>
            <input
              id="att-from"
              type="date"
              value={fromDate}
              onChange={(e) => setFromDate(e.target.value)}
              className="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm"
            />
          </div>
          <div>
            <label htmlFor="att-to" className="block text-xs font-medium text-slate-600">
              Hasta
            </label>
            <input
              id="att-to"
              type="date"
              value={toDate}
              onChange={(e) => setToDate(e.target.value)}
              className="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm"
            />
          </div>
          <div>
            <label htmlFor="att-status" className="block text-xs font-medium text-slate-600">
              Estado
            </label>
            <select
              id="att-status"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm"
            >
              <option value="">Todos</option>
              <option value="working">Trabajando</option>
              <option value="on_break">En descanso</option>
              <option value="closed">Cerrado</option>
            </select>
          </div>
        </div>

        {error && (
          <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
            {error}
          </div>
        )}

        <ResponsiveTable
          caption="Registros de asistencia"
          headers={['Fecha', 'Empleado', 'Sucursal', 'Estado', 'Trabajado', 'Retardo', 'Acciones']}
        >
          {records.map((rec) => (
            <tr key={rec.id} className="hover:bg-slate-50">
              <td data-label="Fecha" className="px-4 py-3 font-mono text-xs">
                {rec.work_date}
              </td>
              <td data-label="Empleado" className="px-4 py-3 font-medium text-slate-900">
                {rec.employee ? `${rec.employee.full_name} (${rec.employee.employee_code})` : `#${rec.employee_id}`}
              </td>
              <td data-label="Sucursal" className="px-4 py-3">
                {rec.branch?.name ?? '—'}
              </td>
              <td data-label="Estado" className="px-4 py-3">
                <StatusBadge value={rec.status} />
              </td>
              <td data-label="Trabajado" className="px-4 py-3 text-xs">
                {rec.total_worked_minutes !== null ? `${rec.total_worked_minutes} min` : 'En curso'}
              </td>
              <td data-label="Retardo" className="px-4 py-3 text-xs">
                {rec.late_minutes} min
              </td>
              <td data-label="Acciones" className="px-4 py-3">
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => void openDetail(rec.id)}
                    className="inline-flex items-center gap-1 rounded border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100"
                  >
                    <Eye className="h-3.5 w-3.5" />
                    <span>Eventos</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => openCorrect(rec)}
                    className="inline-flex items-center gap-1 rounded border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 hover:bg-amber-100"
                  >
                    <Wrench className="h-3.5 w-3.5" />
                    <span>Corregir</span>
                  </button>
                </div>
              </td>
            </tr>
          ))}
        </ResponsiveTable>
      </div>

      <ModalDialog
        open={detail !== null}
        onClose={() => setDetail(null)}
        title={`Historial Append-Only — Registro #${detail?.id ?? ''}`}
        maxWidthClass="max-w-2xl"
      >
        {detail && (
          <div className="space-y-4 text-sm">
            <div className="rounded-lg bg-slate-50 p-3 text-xs text-slate-700">
              <p>
                <strong>Empleado:</strong> {detail.employee?.full_name} | <strong>Fecha:</strong>{' '}
                {detail.work_date}
              </p>
              <p className="mt-1">
                <strong>Descansos:</strong> {detail.total_break_minutes} min |{' '}
                <strong>Salida anticipada:</strong> {detail.early_departure_minutes} min
              </p>
            </div>
            <ul className="divide-y divide-slate-200">
              {(detail.events ?? []).map((ev) => (
                <li key={ev.id} className="py-2.5">
                  <div className="flex items-center justify-between">
                    <span className="font-semibold text-slate-900">{ev.event_type}</span>
                    <span className="font-mono text-xs text-slate-500">
                      {new Date(ev.event_at).toLocaleString('es-MX')}
                    </span>
                  </div>
                  <p className="text-xs text-slate-600">
                    Origen: {ev.source}
                    {ev.distance_meters !== null &&
                      ` | Distancia: ${ev.distance_meters} m (radio ${ev.geofence_radius_meters} m)`}
                  </p>
                  {ev.notes && <p className="mt-1 text-xs italic text-slate-500">Nota: {ev.notes}</p>}
                </li>
              ))}
            </ul>
          </div>
        )}
      </ModalDialog>

      <ModalDialog
        open={correcting !== null}
        onClose={() => setCorrecting(null)}
        title={`Corrección Auditada — Registro #${correcting?.id ?? ''}`}
      >
        <form onSubmit={handleCorrect} className="space-y-3">
          {corrError && (
            <div role="alert" className="rounded bg-rose-50 p-2.5 text-xs text-rose-700">
              {corrError}
            </div>
          )}
          <p className="text-xs text-slate-600">
            Esta acción genera un nuevo evento <code>correction</code> y un registro en{' '}
            <code>audit_logs</code> sin alterar los eventos originales.
          </p>
          <div>
            <label htmlFor="corr-status" className="block text-xs font-medium text-slate-700">
              Estado resultante
            </label>
            <select
              id="corr-status"
              value={corrForm.status}
              onChange={(e) => setCorrForm((s) => ({ ...s, status: e.target.value }))}
              className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
            >
              <option value="closed">Cerrado</option>
              <option value="working">Trabajando</option>
            </select>
          </div>
          <div>
            <label htmlFor="corr-out" className="block text-xs font-medium text-slate-700">
              Hora de salida (local)
            </label>
            <input
              id="corr-out"
              type="datetime-local"
              value={corrForm.clock_out_at}
              onChange={(e) => setCorrForm((s) => ({ ...s, clock_out_at: e.target.value }))}
              className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
          <div>
            <label htmlFor="corr-reason" className="block text-xs font-medium text-slate-700">
              Motivo de la corrección (obligatorio)
            </label>
            <textarea
              id="corr-reason"
              required
              minLength={5}
              rows={3}
              value={corrForm.reason}
              onChange={(e) => setCorrForm((s) => ({ ...s, reason: e.target.value }))}
              className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Ej. Cierre manual por batería agotada al salir."
            />
          </div>
          <div className="flex justify-end gap-2 pt-3">
            <button
              type="button"
              onClick={() => setCorrecting(null)}
              className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-700"
            >
              Cancelar
            </button>
            <button
              type="submit"
              className="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700"
            >
              Aplicar corrección
            </button>
          </div>
        </form>
      </ModalDialog>
    </DashboardShell>
  );
}
