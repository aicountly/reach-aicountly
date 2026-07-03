import { useState } from 'react';
import { useNavigate, Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { Alert } from '../components/common/Alert';
import { ReachLogo } from '../components/brand/ReachLogo';
import { ROUTES } from '../constants/routes';

export function LoginPage() {
  const { login, isAuthenticated, loading, user } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const navigate = useNavigate();

  if (loading) return null;
  if (isAuthenticated && user?.role === 'super_admin') {
    return <Navigate to={ROUTES.DASHBOARD} replace />;
  }

  const submit = async (e) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await login(email.trim(), password);
      navigate(ROUTES.DASHBOARD, { replace: true });
    } catch (err) {
      setError(err.message || 'Login failed.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div style={{
      minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center',
      padding: 16, background: 'linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%)',
    }}>
      <div className="card" style={{ width: 380, maxWidth: '100%', padding: 0 }}>
        <div style={{ padding: '1.5rem 1.5rem 0', textAlign: 'center' }}>
          <ReachLogo height={44} />
          <p className="text-sm text-muted mt-2">Marketing operations — superadmin only</p>
        </div>
        <form onSubmit={submit} style={{ padding: '1.25rem 1.5rem 1.5rem' }}>
          {error && <Alert variant="danger">{error}</Alert>}
          <label className="text-xs text-secondary" style={{ fontWeight: 600 }}>Email</label>
          <input
            type="email" required autoFocus
            value={email} onChange={(e) => setEmail(e.target.value)}
            placeholder="you@aicountly.org"
            className="mb-4" style={{ marginBottom: 12 }}
          />
          <label className="text-xs text-secondary" style={{ fontWeight: 600 }}>Password</label>
          <input
            type="password" required
            value={password} onChange={(e) => setPassword(e.target.value)}
            placeholder="••••••••"
            className="mb-4" style={{ marginBottom: 16 }}
          />
          <button className="btn btn-primary" style={{ width: '100%', justifyContent: 'center' }} disabled={submitting}>
            {submitting ? 'Signing in…' : 'Sign in'}
          </button>
          <p className="text-xs text-muted text-center mt-3">
            Access is restricted to <strong>super_admin</strong> accounts.
          </p>
        </form>
      </div>
    </div>
  );
}
