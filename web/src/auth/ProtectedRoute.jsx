import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { ROUTES } from '../constants/routes';
import { Loader } from '../components/common/Loader';

/**
 * Reach is a superadmin-only portal — every route is guarded by role.
 * `super_admin` is the only accepted role. Any other role is bounced to
 * /login (which will show the credentials form).
 */
export function ProtectedRoute({ children }) {
  const { user, loading, isAuthenticated } = useAuth();

  if (loading) {
    return (
      <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100vh' }}>
        <Loader />
      </div>
    );
  }
  if (!isAuthenticated) {
    return <Navigate to={ROUTES.LOGIN} replace />;
  }
  if ((user?.role || '') !== 'super_admin') {
    return <Navigate to={ROUTES.LOGIN} replace />;
  }
  return children;
}
