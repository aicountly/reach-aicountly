import { createContext, useContext, useState, useCallback, useEffect, useMemo } from 'react';
import { authService } from '../services/authService';
import { clearControllerSsoHash, readControllerSsoToken } from '../services/controllerSso';
import { redirectToConsoleLogin } from '../services/consoleAuth';

const AuthContext = createContext(null);

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
    setLoading(true);
    setGateReason(null);
    setGateMessage('');

    const ssoToken = readControllerSsoToken();
    if (ssoToken) {
      clearControllerSsoHash();
      setSsoPending(true);
      try {
        await loginWithControllerSso(ssoToken);
      } catch (e) {
        setGateReason(GATE_ERROR);
        setGateMessage(e?.message || 'Console SSO login failed');
      } finally {
        setSsoPending(false);
        setLoading(false);
      }
      return;
    }

    if (localStorage.getItem(TOKEN_KEY)) {
      const ok = await refresh();
      if (ok) {
        setLoading(false);
        return;
      }
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
      } else if (status === 403) {
        setGateReason(GATE_NO_ACCESS);
        setGateMessage(message);
      } else {
        setGateReason(GATE_ERROR);
        setGateMessage(message);
      }
    } finally {
      setSsoPending(false);
      setLoading(false);
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
    redirectToConsoleLogin();
  }, []);

  const value = useMemo(() => ({
    user,
    loading,
    ssoPending,
    gateReason,
    gateMessage,
    logout,
    retryAuth,
    isAuthenticated: !!user,
  }), [user, loading, ssoPending, gateReason, gateMessage, logout, retryAuth]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
