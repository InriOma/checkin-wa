'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { Plus, Smartphone, Trash2 } from 'lucide-react';
import { DashboardShell } from '@/components/DashboardShell';
import { ModalDialog } from '@/components/ModalDialog';
import { ResponsiveTable } from '@/components/ResponsiveTable';
import { StatusBadge } from '@/components/StatusBadge';
import { ApiError, apiRequest } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import type {
  Branch,
  Employee,
  EmployeeWhatsAppLink,
  PaginatedResponse,
} from '@/types/api';

export default function EmployeesPage() {
  const { token, activeTenantId } = useAuth();
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [branches, setBranches] = useState<Branch[]>([]);
  const [search, setSearch] = useState('');
  const [error, setError] = useState<string | null>(null);

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<Employee | null>(null);
  const [formState, setFormState] = useState({
    employee_code: '',
    first_name: '',
    last_name: '',
    email: '',
    default_branch_id: '',
    status: 'active',
  });
  const [formError, setFormError] = useState<string | null>(null);

  const [waEmployee, setWaEmployee] = useState<Employee | null>(null);
  const [waLinks, setWaLinks] = useState<EmployeeWhatsAppLink[]>([]);

  const loadEmployees = useCallback(async () => {
    if (!token || !activeTenantId) return;
    const q = search.trim() ? `&search=${encodeURIComponent(search.trim())}` : '';
    try {
      const [empRes, branchRes] = await Promise.all([
        apiRequest<PaginatedResponse<Employee>>(`/employees?per_page=50${q}`, {
          token,
          tenantId: activeTenantId,
        }),
        apiRequest<PaginatedResponse<Branch>>('/branches?per_page=100', {
          token,
          tenantId: activeTenantId,
        }),
      ]);
      setError(null);
      setEmployees(empRes.data);
      setBranches(branchRes.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error cargando empleados.');
    }
  }, [token, activeTenantId, search]);

  useEffect(() => {
    void Promise.resolve().then(() => loadEmployees());
  }, [loadEmployees]);

  const openCreate = () => {
    setEditing(null);
    setFormError(null);
    setFormState({
      employee_code: '',
      first_name: '',
      last_name: '',
      email: '',
      default_branch_id: branches[0]?.id ? String(branches[0].id) : '',
      status: 'active',
    });
    setCreateOpen(true);
  };

  const openEdit = (emp: Employee) => {
    setEditing(emp);
    setFormError(null);
    setFormState({
      employee_code: emp.employee_code,
      first_name: emp.first_name,
      last_name: emp.last_name,
      email: emp.email ?? '',
      default_branch_id: emp.default_branch_id ? String(emp.default_branch_id) : '',
      status: emp.status,
    });
    setCreateOpen(true);
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token || !activeTenantId) return;
    setFormError(null);

    const payload = {
      employee_code: formState.employee_code.trim(),
      first_name: formState.first_name.trim(),
      last_name: formState.last_name.trim(),
      email: formState.email.trim() || null,
      default_branch_id: formState.default_branch_id ? Number(formState.default_branch_id) : null,
      status: formState.status,
    };

    try {
      if (editing) {
        await apiRequest(`/employees/${editing.id}`, {
          method: 'PATCH',
          body: payload,
          token,
          tenantId: activeTenantId,
        });
      } else {
        await apiRequest('/employees', {
          method: 'POST',
          body: payload,
          token,
          tenantId: activeTenantId,
        });
      }
      setCreateOpen(false);
      await loadEmployees();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : 'Error guardando empleado.');
    }
  };

  const openWaLinks = async (emp: Employee) => {
    if (!token || !activeTenantId) return;
    setWaEmployee(emp);
    try {
      const res = await apiRequest<{ data: EmployeeWhatsAppLink[] }>(
        `/employees/${emp.id}/whatsapp-links`,
        { token, tenantId: activeTenantId },
      );
      setWaLinks(res.data);
    } catch {
      setWaLinks([]);
    }
  };

  const revokeWaLink = async (linkId: number) => {
    if (!token || !activeTenantId || !waEmployee) return;
    await apiRequest(`/employees/${waEmployee.id}/whatsapp-links/${linkId}`, {
      method: 'DELETE',
      token,
      tenantId: activeTenantId,
    });
    await openWaLinks(waEmployee);
  };

  return (
    <DashboardShell>
      <div className="space-y-5">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-slate-900">Empleados</h1>
            <p className="text-sm text-slate-500">
              Administra códigos de empleado, sucursal asignada y vínculos de WhatsApp.
            </p>
          </div>
          <button
            type="button"
            onClick={openCreate}
            className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
          >
            <Plus className="h-4 w-4" />
            <span>Nuevo empleado</span>
          </button>
        </div>

        <div className="flex gap-3">
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar por nombre o código..."
            aria-label="Buscar empleados"
            className="w-full max-w-sm rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
          />
        </div>

        {error && (
          <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
            {error}
          </div>
        )}

        <ResponsiveTable
          caption="Directorio de empleados"
          headers={['Código', 'Nombre', 'Correo', 'Sucursal base', 'Estado', 'Acciones']}
        >
          {employees.map((emp) => (
            <tr key={emp.id} className="hover:bg-slate-50">
              <td data-label="Código" className="px-4 py-3 font-mono text-xs font-semibold text-slate-900">
                {emp.employee_code}
              </td>
              <td data-label="Nombre" className="px-4 py-3 font-medium text-slate-900">
                {emp.full_name}
              </td>
              <td data-label="Correo" className="px-4 py-3 text-slate-600">
                {emp.email ?? '—'}
              </td>
              <td data-label="Sucursal base" className="px-4 py-3">
                {emp.default_branch?.name ?? '—'}
              </td>
              <td data-label="Estado" className="px-4 py-3">
                <StatusBadge value={emp.status} />
              </td>
              <td data-label="Acciones" className="px-4 py-3">
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => openEdit(emp)}
                    className="rounded border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100"
                  >
                    Editar
                  </button>
                  <button
                    type="button"
                    onClick={() => void openWaLinks(emp)}
                    className="inline-flex items-center gap-1 rounded border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100"
                  >
                    <Smartphone className="h-3.5 w-3.5" />
                    <span>WhatsApp</span>
                  </button>
                </div>
              </td>
            </tr>
          ))}
        </ResponsiveTable>
      </div>

      <ModalDialog
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title={editing ? `Editar ${editing.employee_code}` : 'Nuevo empleado'}
      >
        <form onSubmit={handleSave} className="space-y-3">
          {formError && (
            <div role="alert" className="rounded bg-rose-50 p-2.5 text-xs text-rose-700">
              {formError}
            </div>
          )}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label htmlFor="emp-code" className="block text-xs font-medium text-slate-700">
                Código
              </label>
              <input
                id="emp-code"
                required
                value={formState.employee_code}
                onChange={(e) => setFormState((s) => ({ ...s, employee_code: e.target.value }))}
                className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                placeholder="EMP002"
              />
            </div>
            <div>
              <label htmlFor="emp-status" className="block text-xs font-medium text-slate-700">
                Estado
              </label>
              <select
                id="emp-status"
                value={formState.status}
                onChange={(e) => setFormState((s) => ({ ...s, status: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
              >
                <option value="active">Activo</option>
                <option value="inactive">Inactivo</option>
                <option value="suspended">Suspendido</option>
              </select>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label htmlFor="emp-first" className="block text-xs font-medium text-slate-700">
                Nombre
              </label>
              <input
                id="emp-first"
                required
                value={formState.first_name}
                onChange={(e) => setFormState((s) => ({ ...s, first_name: e.target.value }))}
                className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label htmlFor="emp-last" className="block text-xs font-medium text-slate-700">
                Apellidos
              </label>
              <input
                id="emp-last"
                required
                value={formState.last_name}
                onChange={(e) => setFormState((s) => ({ ...s, last_name: e.target.value }))}
                className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
          </div>
          <div>
            <label htmlFor="emp-email" className="block text-xs font-medium text-slate-700">
              Correo (opcional)
            </label>
            <input
              id="emp-email"
              type="email"
              value={formState.email}
              onChange={(e) => setFormState((s) => ({ ...s, email: e.target.value }))}
              className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
          <div>
            <label htmlFor="emp-branch" className="block text-xs font-medium text-slate-700">
              Sucursal predeterminada
            </label>
            <select
              id="emp-branch"
              value={formState.default_branch_id}
              onChange={(e) => setFormState((s) => ({ ...s, default_branch_id: e.target.value }))}
              className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
            >
              <option value="">Sin sucursal predeterminada</option>
              {branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name} ({b.code})
                </option>
              ))}
            </select>
          </div>

          <div className="flex justify-end gap-2 pt-3">
            <button
              type="button"
              onClick={() => setCreateOpen(false)}
              className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-700"
            >
              Cancelar
            </button>
            <button
              type="submit"
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
            >
              Guardar empleado
            </button>
          </div>
        </form>
      </ModalDialog>

      <ModalDialog
        open={waEmployee !== null}
        onClose={() => setWaEmployee(null)}
        title={`Vínculos WhatsApp — ${waEmployee?.full_name ?? ''}`}
      >
        {waLinks.length === 0 ? (
          <p className="text-sm text-slate-600">
            Este empleado aún no tiene un número de WhatsApp verificado.
          </p>
        ) : (
          <ul className="divide-y divide-slate-200">
            {waLinks.map((link) => (
              <li key={link.id} className="flex items-center justify-between py-3">
                <div>
                  <p className="font-mono text-sm font-medium text-slate-900">
                    {link.phone_masked ?? 'Número vinculado'}
                  </p>
                  <p className="text-xs text-slate-500">
                    Verificado: {link.verified_at ? new Date(link.verified_at).toLocaleString('es-MX') : 'Pendiente'}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => void revokeWaLink(link.id)}
                  className="inline-flex items-center gap-1 rounded border border-rose-200 px-2.5 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50"
                >
                  <Trash2 className="h-3.5 w-3.5" />
                  <span>Desvincular</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </ModalDialog>
    </DashboardShell>
  );
}
