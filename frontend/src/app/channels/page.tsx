'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { Plus, ShieldCheck } from 'lucide-react';
import { DashboardShell } from '@/components/DashboardShell';
import { ModalDialog } from '@/components/ModalDialog';
import { ResponsiveTable } from '@/components/ResponsiveTable';
import { StatusBadge } from '@/components/StatusBadge';
import { ApiError, apiRequest } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import type { PaginatedResponse, WhatsAppChannel } from '@/types/api';

export default function ChannelsPage() {
  const { token, activeTenantId } = useAuth();
  const [channels, setChannels] = useState<WhatsAppChannel[]>([]);
  const [modalOpen, setModalOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [form, setForm] = useState({
    phone_number_id: '',
    business_account_id: '',
    display_phone_number: '',
    access_token: '',
  });

  const loadChannels = useCallback(async () => {
    if (!token || !activeTenantId) return;
    try {
      const res = await apiRequest<PaginatedResponse<WhatsAppChannel>>('/whatsapp/channels', {
        token,
        tenantId: activeTenantId,
      });
      setError(null);
      setChannels(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error cargando canales WhatsApp.');
    }
  }, [token, activeTenantId]);

  useEffect(() => {
    void Promise.resolve().then(() => loadChannels());
  }, [loadChannels]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token || !activeTenantId) return;
    setFormError(null);

    try {
      await apiRequest('/whatsapp/channels', {
        method: 'POST',
        body: {
          channel_type: 'dedicated',
          phone_number_id: form.phone_number_id.trim(),
          business_account_id: form.business_account_id.trim(),
          display_phone_number: form.display_phone_number.trim(),
          access_token: form.access_token.trim(),
          status: 'active',
        },
        token,
        tenantId: activeTenantId,
      });
      setModalOpen(false);
      setForm({
        phone_number_id: '',
        business_account_id: '',
        display_phone_number: '',
        access_token: '',
      });
      await loadChannels();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : 'Error registrando canal.');
    }
  };

  return (
    <DashboardShell>
      <div className="space-y-5">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-slate-900">Canales WhatsApp Business</h1>
            <p className="text-sm text-slate-500">
              Conecta números propios de WhatsApp Cloud API para tu empresa. Los tokens se cifran en servidor.
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
            <span>Conectar canal dedicado</span>
          </button>
        </div>

        {error && (
          <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
            {error}
          </div>
        )}

        <ResponsiveTable
          caption="Canales de WhatsApp conectados"
          headers={['Tipo', 'Número visible', 'Phone Number ID', 'WABA ID', 'Token', 'Estado']}
        >
          {channels.length === 0 ? (
            <tr>
              <td colSpan={6} className="px-4 py-6 text-center text-slate-500">
                No hay canales dedicados configurados; la empresa opera con el canal central.
              </td>
            </tr>
          ) : (
            channels.map((ch) => (
              <tr key={ch.id} className="hover:bg-slate-50">
                <td data-label="Tipo" className="px-4 py-3 font-medium capitalize text-slate-900">
                  {ch.channel_type}
                </td>
                <td data-label="Número visible" className="px-4 py-3 font-mono text-xs">
                  {ch.display_phone_number}
                </td>
                <td data-label="Phone Number ID" className="px-4 py-3 font-mono text-xs">
                  {ch.phone_number_id}
                </td>
                <td data-label="WABA ID" className="px-4 py-3 font-mono text-xs">
                  {ch.business_account_id}
                </td>
                <td data-label="Token" className="px-4 py-3 text-xs text-emerald-700">
                  {ch.token_configured ? (
                    <span className="inline-flex items-center gap-1">
                      <ShieldCheck className="h-3.5 w-3.5" /> Cifrado
                    </span>
                  ) : (
                    'Sin configurar'
                  )}
                </td>
                <td data-label="Estado" className="px-4 py-3">
                  <StatusBadge value={ch.status} />
                </td>
              </tr>
            ))
          )}
        </ResponsiveTable>
      </div>

      <ModalDialog
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title="Conectar canal dedicado de WhatsApp"
      >
        <form onSubmit={handleCreate} className="space-y-3">
          {formError && (
            <div role="alert" className="rounded bg-rose-50 p-2.5 text-xs text-rose-700">
              {formError}
            </div>
          )}
          <div>
            <label htmlFor="wa-phone" className="block text-xs font-medium text-slate-700">
              Número visible (E.164)
            </label>
            <input
              id="wa-phone"
              required
              value={form.display_phone_number}
              onChange={(e) => setForm((s) => ({ ...s, display_phone_number: e.target.value }))}
              className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="+525512345678"
            />
          </div>
          <div>
            <label htmlFor="wa-pnid" className="block text-xs font-medium text-slate-700">
              Meta Phone Number ID
            </label>
            <input
              id="wa-pnid"
              required
              value={form.phone_number_id}
              onChange={(e) => setForm((s) => ({ ...s, phone_number_id: e.target.value }))}
              className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
          <div>
            <label htmlFor="wa-waba" className="block text-xs font-medium text-slate-700">
              WhatsApp Business Account ID (WABA)
            </label>
            <input
              id="wa-waba"
              required
              value={form.business_account_id}
              onChange={(e) => setForm((s) => ({ ...s, business_account_id: e.target.value }))}
              className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
          <div>
            <label htmlFor="wa-token" className="block text-xs font-medium text-slate-700">
              Access Token de Meta Cloud API
            </label>
            <input
              id="wa-token"
              type="password"
              required
              minLength={10}
              value={form.access_token}
              onChange={(e) => setForm((s) => ({ ...s, access_token: e.target.value }))}
              className="field-input mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
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
              Guardar canal
            </button>
          </div>
        </form>
      </ModalDialog>
    </DashboardShell>
  );
}
