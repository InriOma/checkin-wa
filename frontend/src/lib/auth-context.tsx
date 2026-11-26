'use client';

import React, { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { apiRequest } from '@/lib/api';
import type { Tenant, TenantMembership, User } from '@/types/api';

interface AuthContextValue {
  user: User | null;
  token: string | null;
  tenants: Tenant[];
  activeTenantId: number | null;
  activeMembership: TenantMembership | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  selectTenant: (tenantId: number) => void;
}

const TOKEN_STORAGE_KEY = 'checkin_wa_token';
const TENANT_STORAGE_KEY = 'checkin_wa_tenant_id';

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<User | null>(null);
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [activeTenantId, setActiveTenantId] = useState<number | null>(null);
  const [loading, setLoading] = useState<boolean>(true);

  const hydrateSession = useCallback(async (savedToken: string) => {
    try {
      const [meRes, tenantsRes] = await Promise.all([
        apiRequest<{ data: User }>('/auth/me', { token: savedToken }),
        apiRequest<{ data: Tenant[] }>('/auth/tenants', { token: savedToken }),
      ]);

      setUser(meRes.data);
      setTenants(tenantsRes.data);

      const savedTenantRaw =
        typeof window !== 'undefined' ? window.localStorage.getItem(TENANT_STORAGE_KEY) : null;
      const savedTenant = savedTenantRaw ? Number(savedTenantRaw) : null;

      const validSaved = tenantsRes.data.find((t) => t.id === savedTenant);
      const nextTenantId = validSaved?.id ?? tenantsRes.data[0]?.id ?? null;

      setActiveTenantId(nextTenantId);
      if (nextTenantId !== null && typeof window !== 'undefined') {
        window.localStorage.setItem(TENANT_STORAGE_KEY, String(nextTenantId));
      }
    } catch {
      if (typeof window !== 'undefined') {
        window.localStorage.removeItem(TOKEN_STORAGE_KEY);
        window.localStorage.removeItem(TENANT_STORAGE_KEY);
      }
      setToken(null);
      setUser(null);
      setTenants([]);
      setActiveTenantId(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let active = true;
    const saved =
      typeof window !== 'undefined' ? window.localStorage.getItem(TOKEN_STORAGE_KEY) : null;

    void Promise.resolve().then(async () => {
      if (!active) return;
      if (!saved) {
        setLoading(false);
        return;
      }
      setToken(saved);
      await hydrateSession(saved);
    });

    return () => {
      active = false;
    };
  }, [hydrateSession]);

  const login = useCallback(
    async (email: string, password: string) => {
      const res = await apiRequest<{ token: string; user: User }>('/auth/login', {
        method: 'POST',
        body: { email, password, device_name: 'nextjs-dashboard' },
      });

      if (typeof window !== 'undefined') {
        window.localStorage.setItem(TOKEN_STORAGE_KEY, res.token);
      }
      setToken(res.token);
      setLoading(true);
      await hydrateSession(res.token);
    },
    [hydrateSession],
  );

  const logout = useCallback(async () => {
    if (token) {
      try {
        await apiRequest('/auth/logout', { method: 'POST', token });
      } catch {
        // Ignore network errors during logout.
      }
    }

    if (typeof window !== 'undefined') {
      window.localStorage.removeItem(TOKEN_STORAGE_KEY);
      window.localStorage.removeItem(TENANT_STORAGE_KEY);
    }
    setToken(null);
    setUser(null);
    setTenants([]);
    setActiveTenantId(null);
  }, [token]);

  const selectTenant = useCallback((tenantId: number) => {
    setActiveTenantId(tenantId);
    if (typeof window !== 'undefined') {
      window.localStorage.setItem(TENANT_STORAGE_KEY, String(tenantId));
    }
  }, []);

  const activeMembership =
    user?.memberships?.find((m) => m.tenant_id === activeTenantId) ?? null;

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        tenants,
        activeTenantId,
        activeMembership,
        loading,
        login,
        logout,
        selectTenant,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used inside AuthProvider.');
  }
  return ctx;
}
