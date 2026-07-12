import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { ROUTES } from '../constants/routes';
import { Loader } from '../components/common/Loader';
import { ForbiddenPage } from '../pages/ForbiddenPage';

/**
 * Guards a route with:
 *   - Loading state while auth bootstraps
 *   - Redirect to Dashboard (which triggers the ControllerGate flow) when unauthenticated
 *   - ForbiddenPage when authenticated but missing the required permission
 *
 * The `permission` prop is optional; when omitted, any authenticated user
 * (previously any `super_admin`) may enter. Backend filters remain authoritative.
 */
export function ProtectedRoute({ children, permission }) {
  const { loading, isAuthenticated, hasPermission } = useAuth();

  if (loading) {
    return (
      <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100vh' }}>
        <Loader />
      </div>
    );
  }
  if (!isAuthenticated) {
    return <Navigate to={ROUTES.DASHBOARD} replace />;
  }
  if (permission && !hasPermission(permission)) {
    return <ForbiddenPage requiredPermission={permission} />;
  }
  return children;
}
