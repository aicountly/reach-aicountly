import { Link } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import { ROUTES } from '../constants/routes';
import { useAuth } from '../context/AuthContext';

/**
 * Rendered mid-session when the authenticated user is missing the specific
 * permission required for a route. Distinct from the bootstrap-time
 * ControllerGate which handles unauthenticated / no-access scenarios.
 */
export function ForbiddenPage({ requiredPermission }) {
  const { user } = useAuth();
  return (
    <div
      role="alert"
      style={{
        minHeight: '60vh',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '2rem',
        textAlign: 'center',
      }}
    >
      <ShieldAlert size={40} style={{ color: 'var(--color-danger)' }} aria-hidden />
      <h2 style={{ marginTop: '1rem' }}>Access denied</h2>
      <p className="text-sm text-muted" style={{ maxWidth: 480 }}>
        Your account ({user?.email || 'unknown'}) does not have the required permission
        {requiredPermission ? (
          <>
            {' '}<code>{requiredPermission}</code>
          </>
        ) : null} to view this page. Ask an administrator to grant access.
      </p>
      <Link to={ROUTES.DASHBOARD} className="btn btn-secondary" style={{ marginTop: '1.25rem' }}>
        Back to Dashboard
      </Link>
    </div>
  );
}
