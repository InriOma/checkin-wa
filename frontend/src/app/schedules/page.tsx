'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { CalendarPlus, Plus } from 'lucide-react';
import { DashboardShell } from '@/components/DashboardShell';
import { ModalDialog } from '@/components/ModalDialog';
import { ResponsiveTable } from '@/components/ResponsiveTable';
import { StatusBadge } from '@/components/StatusBadge';
import { ApiError, apiRequest } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import type { Employee, PaginatedResponse, Schedule } from '@/types/api';

const WEEKDAYS = [
  { id: 1, label: 'Lun' },
  { id: 2, label: 'Mar' },
  { id: 3, label: 'Mié' },
  { id: 4, label: 'Jue' },
  { id: 5, label: 'Vie' },
  { id: 6, label: 'Sáb' },
  { id: 7, label: 'Dom' },
];

export default function SchedulesPage() {
  const { token, activeTenantId } = useAuth();
  const [schedules, setSchedules] = useState<Schedule[]>([]);
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [modalOpen, setModalOpen] = useState(false);
  const [assignSchedule, setAssignSchedule] = useState<Schedule | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const [form, setForm] = useState({
    name: '',
    timezone: 'America/Mexico_City',
    work_days: [1, 2, 3, 4, 5],
    start_time: '09:00',
    end_time: '18:00',
    late_tolerance_minutes: '10',
    absence_threshold_minutes: '120',
    break_duration_minutes: '30',
    max_breaks_per_shift: '2',
  });

  const [assignForm, setAssignForm] = useState({
    employee_id: '',
    effective_from: '',
    effective_until: '',
  });

  const loadData = useCallback(async () => {
    if (!token || !activeTenantId) return;
    try {
      const [schRes, empRes] = await Promise.all([
        apiRequest<PaginatedResponse<Schedule>>('/schedules?per_page=50', {
          token,
          tenantId: activeTenantId,
        }),
        apiRequest<PaginatedResponse<Employee>>('/employees?per_page=100', {
          token,
          tenantId: activeTenantId,
        }),
      ]);
      setError(null);
      setSchedules(schRes.data);
      setEmployees(empRes.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error cargando horarios.');
    }
  }, [token, activeTenantId]);

  useEffect(() => {
    void Promise.resolve().then(() => loadData());
  }, [loadData]);

  const toggleDay = (day: number) => {
    setForm((s) => {
      const exists = s.work_days.includes(day);
      const next = exists ? s.work_days.filter((d) => d !== day) : [...s.work_days, day].sort();
      return { ...s, work_days: next };
    });
  };

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token || !activeTenantId) return;
    setFormError(null);

    try {
      await apiRequest('/schedules', {
        method: 'POST',
        body: {
          name: form.name.trim(),
          timezone: form.timezone.trim(),
          work_days: form.work_days,
          start_time: form.start_time,
          end_time: form.end_time,
          late_tolerance_minutes: Number(form.late_tolerance_minutes),
          absence_threshold_minutes: Number(form.absence_threshold_minutes),
          break_duration_minutes: Number(form.break_duration_minutes),
          max_breaks_per_shift: Number(form.max_breaks_per_shift),
        },
        token,
        tenantId: activeTenantId,
      });
      setModalOpen(false);
      await loadData();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : 'Error creando horario.');
    }
  };

  const handleAssign = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token || !activeTenantId || !assignSchedule) return;
    setFormError(null);
    try {
      await apiRequest(`/schedules/${assignSchedule.id}/assign`, {
        method: 'POST',
        body: {
          employee_id: Number(assignForm.employee_id),
          effective_from: assignForm.effective_from,
          effective_until: assignForm.effective_until || null,
        },
        token,
        tenantId: activeTenantId,
      });
      setAssignSchedule(null);
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : 'Error asignando horario.');
    }
  };

  return (
    <DashboardShell>
      <div className="space-y-5">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-slate-900">Horarios y Turnos</h1>
            <p className="text-sm text-slate-500">
              Configura turnos diurnos o nocturnos, tolerancias, descansos y asignaciones.
            </p>
          </div>
          <button
            type="button"
            onClick={() => {
              setFormError(null);
              setModalOpen(true);
            }}
            className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
          >
            <Plus className="h-4 w-4" />
            <span>Nuevo horario</span>
          </button>
        </div>

        {error && (
          <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
            {error}
          </div>
        )}

        <ResponsiveTable
          caption="Catálogo de horarios"
          headers={['Nombre', 'Horario', 'Días', 'Tolerancia', 'Descanso', 'Estado', 'Acciones']}
        >
          {schedules.map((sch) => (
            <tr key={sch.id} className="hover:bg-slate-50">
              <td data-label="Nombre" className="px-4 py-3 font-medium text-slate-900">
                <div>{sch.name}</div>
                <div className="text-xs text-slate-500">{sch.timezone}</div>
              </td>
              <td data-label="Horario" className="px-4 py-3 font-mono text-xs">
                {sch.start_time.slice(0, 5)} – {sch.end_time.slice(0, 5)}
                {sch.is_overnight && (
                  <span className="ml-1.5 rounded bg-indigo-50 px-1.5 py-0.5 text-[11px] font-medium text-indigo-700">
                    Nocturno
                  </span>
                )}
              </td>
              <td data-label="Días" className="px-4 py-3 text-xs">
                {sch.work_days
                  .map((d) => WEEKDAYS.find((w) => w.id === d)?.label ?? d)
                  .join(', ')}
              </td>
              <td data-label="Tolerancia" className="px-4 py-3 text-xs">
                {sch.late_tolerance_minutes} min / Falta: {sch.absence_threshold_minutes} min
              </td>
              <td data-label="Descanso" className="px-4 py-3 text-xs">
                {sch.break_duration_minutes} min (máx {sch.max_breaks_per_shift})
              </td>
              <td data-label="Estado" className="px-4 py-3">
                <StatusBadge value={sch.status} />
              </td>
              <td data-label="Acciones" className="px-4 py-3">
                <button
                  type="button"
                  onClick={() => {
                    setFormError(null);
                    const today = new Date().toISOString().slice(0, 10);
                    setAssignForm((s) => ({
                      ...s,
                      employee_id: employees[0]?.id ? String(employees[0].id) : '',
                      effective_from: s.effective_from || today,
                    }));
                    setAssignSchedule(sch);
                  }}
                  className="inline-flex items-center gap-1 rounded border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100"
                >
                  <CalendarPlus className="h-3.5 w-3.5" />
                  <span>Asignar</span>
                </button>
              </td>
            </tr>
          ))}
        </ResponsiveTable>
      </div>

      <ModalDialog open={modalOpen} onClose={() => setModalOpen(false)} title="Nuevo horario">
        <form onSubmit={handleCreate} className="space-y-3">
          {formError && (
            <div role="alert" className="rounded bg-rose-50 p-2.5 text-xs text-rose-700">
              {formError}
            </div>
          )}
          <div>
            <label htmlFor="sch-name" className="block text-xs font-medium text-slate-700">
              Nombre del turno
            </label>
            <input
              id="sch-name"
              required
              value={form.name}
              onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))}
              className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
          <div>
            <span className="block text-xs font-medium text-slate-700">Días laborables</span>
            <div className="mt-1 flex flex-wrap gap-1.5">
              {WEEKDAYS.map((day) => {
                const selected = form.work_days.includes(day.id);
                return (
                  <button
                    key={day.id}
                    type="button"
                    onClick={() => toggleDay(day.id)}
                    aria-pressed={selected}
                    className={`rounded-lg border px-2.5 py-1 text-xs font-medium ${
                      selected
                        ? 'border-emerald-600 bg-emerald-50 text-emerald-800'
                        : 'border-slate-200 bg-white text-slate-600'
                    }`}
                  >
                    {day.label}
                  </button>
                );
              })}
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label htmlFor="sch-start" className="block text-xs font-medium text-slate-700">
                Hora entrada
              </label>
              <input
                id="sch-start"
                type="time"
                required
                value={form.start_time}
                onChange={(e) => setForm((s) => ({ ...s, start_time: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label htmlFor="sch-end" className="block text-xs font-medium text-slate-700">
                Hora salida
              </label>
              <input
                id="sch-end"
                type="time"
                required
                value={form.end_time}
                onChange={(e) => setForm((s) => ({ ...s, end_time: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label htmlFor="sch-tol" className="block text-xs font-medium text-slate-700">
                Tolerancia retardo (min)
              </label>
              <input
                id="sch-tol"
                type="number"
                min={0}
                value={form.late_tolerance_minutes}
                onChange={(e) => setForm((s) => ({ ...s, late_tolerance_minutes: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label htmlFor="sch-abs" className="block text-xs font-medium text-slate-700">
                Umbral falta (min)
              </label>
              <input
                id="sch-abs"
                type="number"
                min={1}
                value={form.absence_threshold_minutes}
                onChange={(e) => setForm((s) => ({ ...s, absence_threshold_minutes: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
          </div>
          <div>
            <label htmlFor="sch-tz" className="block text-xs font-medium text-slate-700">
              Zona horaria IANA
            </label>
            <input
              id="sch-tz"
              required
              value={form.timezone}
              onChange={(e) => setForm((s) => ({ ...s, timezone: e.target.value }))}
              className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
          <div className="flex justify-end gap-2 pt-3">
            <button
              type="button"
              onClick={() => setModalOpen(false)}
              className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-700"
            >
              Cancelar
            </button>
            <button
              type="submit"
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
            >
              Guardar horario
            </button>
          </div>
        </form>
      </ModalDialog>

      <ModalDialog
        open={assignSchedule !== null}
        onClose={() => setAssignSchedule(null)}
        title={`Asignar ${assignSchedule?.name ?? ''}`}
      >
        <form onSubmit={handleAssign} className="space-y-3">
          {formError && (
            <div role="alert" className="rounded bg-rose-50 p-2.5 text-xs text-rose-700">
              {formError}
            </div>
          )}
          <div>
            <label htmlFor="as-emp" className="block text-xs font-medium text-slate-700">
              Empleado
            </label>
            <select
              id="as-emp"
              required
              value={assignForm.employee_id}
              onChange={(e) => setAssignForm((s) => ({ ...s, employee_id: e.target.value }))}
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
              <label htmlFor="as-from" className="block text-xs font-medium text-slate-700">
                Vigente desde
              </label>
              <input
                id="as-from"
                type="date"
                required
                value={assignForm.effective_from}
                onChange={(e) => setAssignForm((s) => ({ ...s, effective_from: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label htmlFor="as-until" className="block text-xs font-medium text-slate-700">
                Hasta (opcional)
              </label>
              <input
                id="as-until"
                type="date"
                value={assignForm.effective_until}
                onChange={(e) => setAssignForm((s) => ({ ...s, effective_until: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
          </div>
          <div className="flex justify-end gap-2 pt-3">
            <button
              type="button"
              onClick={() => setAssignSchedule(null)}
              className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-700"
            >
              Cancelar
            </button>
            <button
              type="submit"
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
            >
              Asignar turno
            </button>
          </div>
        </form>
      </ModalDialog>
    </DashboardShell>
  );
}
