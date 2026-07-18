import { createContext, useContext, useState, useCallback, useEffect, useMemo, useRef } from 'react';
import { authService } from '../services/authService';
import { clearControllerSsoHash, readControllerSsoToken } from '../services/controllerSso';
import { redirectToConsoleLogin, redirectToConsoleLoginAfterSignOut } from '../services/consoleAuth';

// eslint-disable-next-line react-refresh/only-export-components
export const AuthContext = createContext(null);

export const GATE_CONSOLE_REQUIRED = 'console_required';
export const GATE_NO_ACCESS = 'no_access';
export const GATE_ERROR = 'error';

const TOKEN_KEY = 'reach_token';

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [ssoPending, setSsoPending] = useState(false);
  const [gateReason, setGateReason] = useState(null);
  const [gateMessage, setGateMessage] = useState('');
  const bootstrapRunRef = useRef(0);

  const applySession = useCallback((data) => {
    if (!data?.token) throw new Error('Session succeeded but no token was returned');
    localStorage.setItem(TOKEN_KEY, data.token);
    setUser(data.user);
    setGateReason(null);
    setGateMessage('');
    return data.user;
  }, []);

  const refresh = useCallback(async () => {
    const token = localStorage.getItem(TOKEN_KEY);
    if (!token) {
      setUser(null);
      return false;
    }
    try {
      const data = await authService.me();
      setUser(data);
      setGateReason(null);
      setGateMessage('');
      return true;
    } catch {
      localStorage.removeItem(TOKEN_KEY);
      setUser(null);
      return false;
    }
  }, []);

  const loginWithControllerSso = useCallback(async (ssoToken) => {
    const data = await authService.controllerSso(ssoToken);
    return applySession(data);
  }, [applySession]);

  const loginWithConsoleSession = useCallback(async () => {
    const data = await authService.consoleSession();
    return applySession(data);
  }, [applySession]);

  const bootstrap = useCallback(async () => {
    const runId = ++bootstrapRunRef.current;
    const finish = () => {
      if (runId !== bootstrapRunRef.current) return;
      setSsoPending(false);
      setLoading(false);
    };

    setLoading(true);
    setSsoPending(false);
    setGateReason(null);
    setGateMessage('');

    try {
      const ssoToken = readControllerSsoToken();
      if (ssoToken) {
        clearControllerSsoHash();
        setSsoPending(true);
        try {
          await loginWithControllerSso(ssoToken);
        } catch (e) {
          setGateReason(GATE_ERROR);
          setGateMessage(e?.message || 'Console SSO login failed');
        }
        return;
      }

      if (localStorage.getItem(TOKEN_KEY)) {
        const ok = await refresh();
        if (ok) return;
      }

      setSsoPending(true);
      try {
        await loginWithConsoleSession();
      } catch (e) {
        const status = e?.status;
        const message = e?.message || 'Could not sign in via Console';
        if (status === 401) {
          redirectToConsoleLogin();
          return;
        }
        if (status === 403) {
          setGateReason(GATE_NO_ACCESS);
          setGateMessage(message);
        } else {
          setGateReason(GATE_ERROR);
          setGateMessage(message);
        }
      }
    } finally {
      finish();
    }
  }, [loginWithConsoleSession, loginWithControllerSso, refresh]);

  useEffect(() => {
    bootstrap();
  }, [bootstrap]);

  const retryAuth = useCallback(async () => {
    localStorage.removeItem(TOKEN_KEY);
    setUser(null);
    await bootstrap();
  }, [bootstrap]);

  const logout = useCallback(async () => {
    try {
      await authService.logout();
    } catch {
      /* ignore */
    }
    localStorage.removeItem(TOKEN_KEY);
    setUser(null);
    await redirectToConsoleLoginAfterSignOut();
  }, []);

  const value = useMemo(() => {
    // Permissions arrive on the user payload from /v1/me (Phase 0 RBAC).
    // A wildcard "*" grants everything (super_admin). Legacy responses that
    // do not include permissions fall back to the historical super_admin gate.
    const list = Array.isArray(user?.permissions) ? user.permissions : [];
    const permissions = new Set(list);
    if (permissions.size === 0 && user?.role === 'super_admin') {
      permissions.add('*');
    }
    const hasPermission = (perm) => {
      if (!perm) return true;
      if (permissions.has('*')) return true;
      if (permissions.has(perm)) return true;
      const [group] = perm.split('.');
      return permissions.has(`${group}.*`);
    };
    return {
      user,
      loading,
      ssoPending,
      gateReason,
      gateMessage,
      logout,
      retryAuth,
      isAuthenticated: !!user,
      permissions,
      hasPermission,
    };
  }, [user, loading, ssoPending, gateReason, gateMessage, logout, retryAuth]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
