'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { MapPin, Plus } from 'lucide-react';
import { DashboardShell } from '@/components/DashboardShell';
import { ModalDialog } from '@/components/ModalDialog';
import { ResponsiveTable } from '@/components/ResponsiveTable';
import { StatusBadge } from '@/components/StatusBadge';
import { ApiError, apiRequest } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import type { Branch, PaginatedResponse } from '@/types/api';

export default function BranchesPage() {
  const { token, activeTenantId } = useAuth();
  const [branches, setBranches] = useState<Branch[]>([]);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Branch | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [form, setForm] = useState({
    code: '',
    name: '',
    address: '',
    latitude: '19.42847',
    longitude: '-99.16283',
    geofence_radius_meters: '120',
    timezone: 'America/Mexico_City',
    status: 'active',
  });

  const loadBranches = useCallback(async () => {
    if (!token || !activeTenantId) return;
    try {
      const res = await apiRequest<PaginatedResponse<Branch>>('/branches?per_page=50', {
        token,
        tenantId: activeTenantId,
      });
      setError(null);
      setBranches(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error cargando sucursales.');
    }
  }, [token, activeTenantId]);

  useEffect(() => {
    void Promise.resolve().then(() => loadBranches());
  }, [loadBranches]);

  const openNew = () => {
    setEditing(null);
    setFormError(null);
    setForm({
      code: '',
      name: '',
      address: '',
      latitude: '19.42847',
      longitude: '-99.16283',
      geofence_radius_meters: '120',
      timezone: 'America/Mexico_City',
      status: 'active',
    });
    setModalOpen(true);
  };

  const openEdit = (branch: Branch) => {
    setEditing(branch);
    setFormError(null);
    setForm({
      code: branch.code,
      name: branch.name,
      address: branch.address ?? '',
      latitude: String(branch.latitude),
      longitude: String(branch.longitude),
      geofence_radius_meters: String(branch.geofence_radius_meters),
      timezone: branch.timezone,
      status: branch.status,
    });
    setModalOpen(true);
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token || !activeTenantId) return;
    setFormError(null);

    const payload = {
      code: form.code.trim(),
      name: form.name.trim(),
      address: form.address.trim() || null,
      latitude: Number(form.latitude),
      longitude: Number(form.longitude),
      geofence_radius_meters: Number(form.geofence_radius_meters),
      timezone: form.timezone.trim(),
      status: form.status,
    };

    try {
      if (editing) {
        await apiRequest(`/branches/${editing.id}`, {
          method: 'PATCH',
          body: payload,
          token,
          tenantId: activeTenantId,
        });
      } else {
        await apiRequest('/branches', {
          method: 'POST',
          body: payload,
          token,
          tenantId: activeTenantId,
        });
      }
      setModalOpen(false);
      await loadBranches();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : 'Error guardando sucursal.');
    }
  };

  return (
    <DashboardShell>
      <div className="space-y-5">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-slate-900">Sucursales y Geocercas</h1>
            <p className="text-sm text-slate-500">
              Coordenadas GPS, radio de tolerancia en metros y zona horaria IANA por sede.
            </p>
          </div>
          <button
            type="button"
            onClick={openNew}
            className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
          >
            <Plus className="h-4 w-4" />
            <span>Nueva sucursal</span>
          </button>
        </div>

        {error && (
          <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
            {error}
          </div>
        )}

        <ResponsiveTable
          caption="Catálogo de sucursales"
          headers={['Código', 'Nombre', 'Coordenadas', 'Radio (m)', 'Zona horaria', 'Estado', 'Acciones']}
        >
          {branches.map((branch) => (
            <tr key={branch.id} className="hover:bg-slate-50">
              <td data-label="Código" className="px-4 py-3 font-mono text-xs font-semibold text-slate-900">
                {branch.code}
              </td>
              <td data-label="Nombre" className="px-4 py-3 font-medium text-slate-900">
                <div>{branch.name}</div>
                {branch.address && <div className="text-xs text-slate-500">{branch.address}</div>}
              </td>
              <td data-label="Coordenadas" className="px-4 py-3 font-mono text-xs">
                <span className="inline-flex items-center gap-1">
                  <MapPin className="h-3.5 w-3.5 text-emerald-600" />
                  {branch.latitude.toFixed(5)}, {branch.longitude.toFixed(5)}
                </span>
              </td>
              <td data-label="Radio (m)" className="px-4 py-3 font-semibold">
                {branch.geofence_radius_meters} m
              </td>
              <td data-label="Zona horaria" className="px-4 py-3 text-xs">
                {branch.timezone}
              </td>
              <td data-label="Estado" className="px-4 py-3">
                <StatusBadge value={branch.status} />
              </td>
              <td data-label="Acciones" className="px-4 py-3">
                <button
                  type="button"
                  onClick={() => openEdit(branch)}
                  className="rounded border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100"
                >
                  Editar
                </button>
              </td>
            </tr>
          ))}
        </ResponsiveTable>
      </div>

      <ModalDialog
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? `Editar sucursal ${editing.code}` : 'Nueva sucursal'}
      >
        <form onSubmit={handleSave} className="space-y-3">
          {formError && (
            <div role="alert" className="rounded bg-rose-50 p-2.5 text-xs text-rose-700">
              {formError}
            </div>
          )}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label htmlFor="br-code" className="block text-xs font-medium text-slate-700">
                Código
              </label>
              <input
                id="br-code"
                required
                value={form.code}
                onChange={(e) => setForm((s) => ({ ...s, code: e.target.value }))}
                className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label htmlFor="br-status" className="block text-xs font-medium text-slate-700">
                Estado
              </label>
              <select
                id="br-status"
                value={form.status}
                onChange={(e) => setForm((s) => ({ ...s, status: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
              >
                <option value="active">Activa</option>
                <option value="inactive">Inactiva</option>
              </select>
            </div>
          </div>
          <div>
            <label htmlFor="br-name" className="block text-xs font-medium text-slate-700">
              Nombre
            </label>
            <input
              id="br-name"
              required
              value={form.name}
              onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))}
              className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
          <div>
            <label htmlFor="br-addr" className="block text-xs font-medium text-slate-700">
              Dirección
            </label>
            <input
              id="br-addr"
              value={form.address}
              onChange={(e) => setForm((s) => ({ ...s, address: e.target.value }))}
              className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <label htmlFor="br-lat" className="block text-xs font-medium text-slate-700">
                Latitud
              </label>
              <input
                id="br-lat"
                type="number"
                step="0.0000001"
                min={-90}
                max={90}
                required
                value={form.latitude}
                onChange={(e) => setForm((s) => ({ ...s, latitude: e.target.value }))}
                className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label htmlFor="br-lon" className="block text-xs font-medium text-slate-700">
                Longitud
              </label>
              <input
                id="br-lon"
                type="number"
                step="0.0000001"
                min={-180}
                max={180}
                required
                value={form.longitude}
                onChange={(e) => setForm((s) => ({ ...s, longitude: e.target.value }))}
                className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label htmlFor="br-rad" className="block text-xs font-medium text-slate-700">
                Radio (m)
              </label>
              <input
                id="br-rad"
                type="number"
                min={10}
                max={50000}
                required
                value={form.geofence_radius_meters}
                onChange={(e) => setForm((s) => ({ ...s, geofence_radius_meters: e.target.value }))}
                className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
          </div>
          <div>
            <label htmlFor="br-tz" className="block text-xs font-medium text-slate-700">
              Zona horaria IANA
            </label>
            <input
              id="br-tz"
              required
              value={form.timezone}
              onChange={(e) => setForm((s) => ({ ...s, timezone: e.target.value }))}
              className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="America/Mexico_City"
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
              Guardar sucursal
            </button>
          </div>
        </form>
      </ModalDialog>
    </DashboardShell>
  );
}
