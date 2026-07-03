import { createContext, useContext, useState, useCallback, useEffect, useMemo } from 'react';
import { authService } from '../services/authService';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser]   = useState(null);
  const [token, setToken] = useState(() => localStorage.getItem('reach_token'));
  const [initialized, setInitialized] = useState(false);

  const login = useCallback(async (email, password) => {
    const data = await authService.login(email, password);
    localStorage.setItem('reach_token', data.token);
    setToken(data.token);
    setUser(data.user);
    return data;
  }, []);

  const logout = useCallback(async () => {
    try { await authService.logout(); } catch { /* ignore */ }
    setToken(null);
    setUser(null);
    localStorage.removeItem('reach_token');
  }, []);

  useEffect(() => {
    if (!token) {
      setInitialized(true);
      return;
    }
    let cancelled = false;
    authService.me()
      .then((data) => { if (!cancelled) setUser(data); })
      .catch(() => {
        if (!cancelled) {
          setToken(null);
          setUser(null);
          localStorage.removeItem('reach_token');
        }
      })
      .finally(() => { if (!cancelled) setInitialized(true); });
    return () => { cancelled = true; };
  }, [token]);

  const loading = !initialized;

  const value = useMemo(() => ({
    user,
    token,
    loading,
    login,
    logout,
    isAuthenticated: !!user,
  }), [user, token, loading, login, logout]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
