'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { Check, Plus, X } from 'lucide-react';
import { DashboardShell } from '@/components/DashboardShell';
import { ModalDialog } from '@/components/ModalDialog';
import { ResponsiveTable } from '@/components/ResponsiveTable';
import { StatusBadge } from '@/components/StatusBadge';
import { ApiError, apiRequest } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import type { Employee, Incident, Justification, PaginatedResponse } from '@/types/api';

export default function IncidentsPage() {
  const { token, activeTenantId } = useAuth();
  const [incidents, setIncidents] = useState<Incident[]>([]);
  const [justifications, setJustifications] = useState<Justification[]>([]);
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [statusFilter, setStatusFilter] = useState('');
  const [error, setError] = useState<string | null>(null);

  const [reviewTarget, setReviewTarget] = useState<{
    incident: Incident;
    decision: 'approve' | 'reject';
  } | null>(null);
  const [reviewNote, setReviewNote] = useState('');
  const [modalError, setModalError] = useState<string | null>(null);

  const [justModalOpen, setJustModalOpen] = useState(false);
  const [justForm, setJustForm] = useState({
    employee_id: '',
    start_date: '',
    end_date: '',
    reason_type: 'medical',
    notes: '',
    status: 'approved',
  });

  const loadData = useCallback(async () => {
    if (!token || !activeTenantId) return;
    const q = statusFilter ? `&status=${statusFilter}` : '';
    try {
      const [incRes, justRes, empRes] = await Promise.all([
        apiRequest<PaginatedResponse<Incident>>(`/incidents?per_page=50${q}`, {
          token,
          tenantId: activeTenantId,
        }),
        apiRequest<PaginatedResponse<Justification>>('/justifications?per_page=30', {
          token,
          tenantId: activeTenantId,
        }),
        apiRequest<PaginatedResponse<Employee>>('/employees?per_page=100', {
          token,
          tenantId: activeTenantId,
        }),
      ]);
      setError(null);
      setIncidents(incRes.data);
      setJustifications(justRes.data);
      setEmployees(empRes.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error cargando incidencias.');
    }
  }, [token, activeTenantId, statusFilter]);

  useEffect(() => {
    void Promise.resolve().then(() => loadData());
  }, [loadData]);

  const handleReview = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token || !activeTenantId || !reviewTarget) return;
    setModalError(null);

    try {
      await apiRequest(`/incidents/${reviewTarget.incident.id}/${reviewTarget.decision}`, {
        method: 'POST',
        body: { justification_note: reviewNote.trim() || null },
        token,
        tenantId: activeTenantId,
      });
      setReviewTarget(null);
      setReviewNote('');
      await loadData();
    } catch (err) {
      setModalError(err instanceof ApiError ? err.message : 'Error al revisar incidencia.');
    }
  };

  const handleCreateJustification = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token || !activeTenantId) return;
    setModalError(null);

    try {
      await apiRequest('/justifications', {
        method: 'POST',
        body: {
          ...justForm,
          employee_id: Number(justForm.employee_id),
        },
        token,
        tenantId: activeTenantId,
      });
      setJustModalOpen(false);
      await loadData();
    } catch (err) {
      setModalError(err instanceof ApiError ? err.message : 'Error al guardar permiso.');
    }
  };

  return (
    <DashboardShell>
      <div className="space-y-8">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-slate-900">Incidencias y Permisos</h1>
            <p className="text-sm text-slate-500">
              Retardos, faltas, jornadas incompletas, geocercas rechazadas y justificaciones.
            </p>
          </div>
          <div className="flex items-center gap-3">
            <select
              aria-label="Filtrar incidencias por estado"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
            >
              <option value="">Todos los estados</option>
              <option value="pending">Pendientes</option>
              <option value="approved">Aprobadas</option>
              <option value="rejected">Rechazadas</option>
            </select>
            <button
              type="button"
              onClick={() => {
                setModalError(null);
                const today = new Date().toISOString().slice(0, 10);
                setJustForm((s) => ({
                  ...s,
                  employee_id: employees[0]?.id ? String(employees[0].id) : '',
                  start_date: s.start_date || today,
                  end_date: s.end_date || today,
                }));
                setJustModalOpen(true);
              }}
              className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
            >
              <Plus className="h-4 w-4" />
              <span>Registrar permiso</span>
            </button>
          </div>
        </div>

        {error && (
          <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
            {error}
          </div>
        )}

        <section className="space-y-3">
          <h2 className="text-base font-semibold text-slate-900">Incidencias detectadas</h2>
          <ResponsiveTable
            caption="Listado de incidencias"
            headers={['Fecha', 'Empleado', 'Tipo', 'Minutos', 'Estado', 'Nota', 'Acciones']}
          >
            {incidents.map((inc) => (
              <tr key={inc.id} className="hover:bg-slate-50">
                <td data-label="Fecha" className="px-4 py-3 font-mono text-xs">
                  {inc.incident_date}
                </td>
                <td data-label="Empleado" className="px-4 py-3 font-medium text-slate-900">
                  {inc.employee?.full_name ?? `#${inc.employee_id}`}
                </td>
                <td data-label="Tipo" className="px-4 py-3">
                  <StatusBadge value={inc.type} />
                </td>
                <td data-label="Minutos" className="px-4 py-3 text-xs">
                  {inc.minutes_affected ?? '—'}
                </td>
                <td data-label="Estado" className="px-4 py-3">
                  <StatusBadge value={inc.status} />
                </td>
                <td data-label="Nota" className="px-4 py-3 text-xs text-slate-600">
                  {inc.justification_note ?? '—'}
                </td>
                <td data-label="Acciones" className="px-4 py-3">
                  {inc.status === 'pending' ? (
                    <div className="flex items-center gap-1.5">
                      <button
                        type="button"
                        onClick={() => {
                          setModalError(null);
                          setReviewNote('');
                          setReviewTarget({ incident: inc, decision: 'approve' });
                        }}
                        className="inline-flex items-center gap-1 rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100"
                      >
                        <Check className="h-3.5 w-3.5" />
                        <span>Aprobar</span>
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          setModalError(null);
                          setReviewNote('');
                          setReviewTarget({ incident: inc, decision: 'reject' });
                        }}
                        className="inline-flex items-center gap-1 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100"
                      >
                        <X className="h-3.5 w-3.5" />
                        <span>Rechazar</span>
                      </button>
                    </div>
                  ) : (
                    <span className="text-xs text-slate-400">Revisada</span>
                  )}
                </td>
              </tr>
            ))}
          </ResponsiveTable>
        </section>

        <section className="space-y-3">
          <h2 className="text-base font-semibold text-slate-900">Permisos y justificaciones</h2>
          <ResponsiveTable
            caption="Listado de permisos y justificaciones"
            headers={['Empleado', 'Desde', 'Hasta', 'Motivo', 'Estado', 'Notas']}
          >
            {justifications.map((j) => (
              <tr key={j.id} className="hover:bg-slate-50">
                <td data-label="Empleado" className="px-4 py-3 font-medium text-slate-900">
                  {j.employee?.full_name ?? `#${j.employee_id}`}
                </td>
                <td data-label="Desde" className="px-4 py-3 font-mono text-xs">
                  {j.start_date}
                </td>
                <td data-label="Hasta" className="px-4 py-3 font-mono text-xs">
                  {j.end_date}
                </td>
                <td data-label="Motivo" className="px-4 py-3 text-xs capitalize">
                  {j.reason_type}
                </td>
                <td data-label="Estado" className="px-4 py-3">
                  <StatusBadge value={j.status} />
                </td>
                <td data-label="Notas" className="px-4 py-3 text-xs text-slate-600">
                  {j.notes ?? '—'}
                </td>
              </tr>
            ))}
          </ResponsiveTable>
        </section>
      </div>

      <ModalDialog
        open={reviewTarget !== null}
        onClose={() => setReviewTarget(null)}
        title={
          reviewTarget?.decision === 'approve' ? 'Aprobar / Justificar incidencia' : 'Rechazar incidencia'
        }
      >
        <form onSubmit={handleReview} className="space-y-3">
          {modalError && (
            <div role="alert" className="rounded bg-rose-50 p-2.5 text-xs text-rose-700">
              {modalError}
            </div>
          )}
          <div>
            <label htmlFor="rev-note" className="block text-xs font-medium text-slate-700">
              Nota de revisión {reviewTarget?.decision === 'reject' ? '(obligatoria)' : '(opcional)'}
            </label>
            <textarea
              id="rev-note"
              required={reviewTarget?.decision === 'reject'}
              rows={3}
              value={reviewNote}
              onChange={(e) => setReviewNote(e.target.value)}
              className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
          <div className="flex justify-end gap-2 pt-3">
            <button
              type="button"
              onClick={() => setReviewTarget(null)}
              className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-700"
            >
              Cancelar
            </button>
            <button
              type="submit"
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
            >
              Confirmar decisión
            </button>
          </div>
        </form>
      </ModalDialog>

      <ModalDialog
        open={justModalOpen}
        onClose={() => setJustModalOpen(false)}
        title="Registrar permiso o incapacidad"
      >
        <form onSubmit={handleCreateJustification} className="space-y-3">
          {modalError && (
            <div role="alert" className="rounded bg-rose-50 p-2.5 text-xs text-rose-700">
              {modalError}
            </div>
          )}
          <div>
            <label htmlFor="just-emp" className="block text-xs font-medium text-slate-700">
              Empleado
            </label>
            <select
              id="just-emp"
              required
              value={justForm.employee_id}
              onChange={(e) => setJustForm((s) => ({ ...s, employee_id: e.target.value }))}
              className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
            >
              {employees.map((emp) => (
                <option key={emp.id} value={emp.id}>
                  {emp.full_name} ({emp.employee_code})
                </option>
              ))}
            </select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label htmlFor="just-start" className="block text-xs font-medium text-slate-700">
                Fecha inicio
              </label>
              <input
                id="just-start"
                type="date"
                required
                value={justForm.start_date}
                onChange={(e) => setJustForm((s) => ({ ...s, start_date: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label htmlFor="just-end" className="block text-xs font-medium text-slate-700">
                Fecha fin
              </label>
              <input
                id="just-end"
                type="date"
                required
                value={justForm.end_date}
                onChange={(e) => setJustForm((s) => ({ ...s, end_date: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label htmlFor="just-type" className="block text-xs font-medium text-slate-700">
                Motivo
              </label>
              <select
                id="just-type"
                value={justForm.reason_type}
                onChange={(e) => setJustForm((s) => ({ ...s, reason_type: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
              >
                <option value="medical">Médico</option>
                <option value="vacation">Vacaciones</option>
                <option value="permission">Permiso con goce</option>
                <option value="personal">Personal</option>
                <option value="other">Otro</option>
              </select>
            </div>
            <div>
              <label htmlFor="just-status" className="block text-xs font-medium text-slate-700">
                Estado
              </label>
              <select
                id="just-status"
                value={justForm.status}
                onChange={(e) => setJustForm((s) => ({ ...s, status: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
              >
                <option value="approved">Aprobado</option>
                <option value="pending">Pendiente</option>
              </select>
            </div>
          </div>
          <div>
            <label htmlFor="just-notes" className="block text-xs font-medium text-slate-700">
              Notas
            </label>
            <textarea
              id="just-notes"
              rows={2}
              value={justForm.notes}
              onChange={(e) => setJustForm((s) => ({ ...s, notes: e.target.value }))}
              className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
          <div className="flex justify-end gap-2 pt-3">
            <button
              type="button"
              onClick={() => setJustModalOpen(false)}
              className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-700"
            >
              Cancelar
            </button>
            <button
              type="submit"
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
            >
              Guardar permiso
            </button>
          </div>
        </form>
      </ModalDialog>
    </DashboardShell>
  );
}
