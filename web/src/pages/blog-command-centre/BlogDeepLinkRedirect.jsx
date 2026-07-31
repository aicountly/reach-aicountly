import { Navigate, useLocation } from 'react-router-dom';
import { findBccLeafByPath } from '../../constants/blogCommandCentreNav';

export function BlogDeepLinkRedirect() {
  const { pathname } = useLocation();
  const leaf = findBccLeafByPath(pathname);
  const target = leaf?.targetPath;

  if (!target) {
    return (
      <div className="empty-state">
        <p>Deep link target is not configured for this route.</p>
      </div>
    );
  }

  return <Navigate to={target} replace />;
}
