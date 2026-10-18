import { Link, useLocation } from 'react-router-dom';
import { Construction } from 'lucide-react';
import { PLANNED_ROUTES } from '../constants/navManifest';

/**
 * Stand-in for a page another stream is building in this release (see
 * PLANNED_ROUTES). It says so plainly; it never pretends the feature works.
 * At merge the real route replaces the placeholder.
 */
export function ComingSoonPage() {
  const { pathname } = useLocation();
  const planned = PLANNED_ROUTES.find((r) => r.path === pathname);
  return (
    <div className="as-empty coming-soon" data-testid="coming-soon">
      <Construction size={32} aria-hidden="true" />
      <h1 className="coming-soon__title">{planned?.label ?? 'This page'}</h1>
      <p>Coming in this release.</p>
      {planned?.description && <p className="text-sm">{planned.description}</p>}
      <p className="text-sm"><Link to="/">Back to the Command centre</Link></p>
    </div>
  );
}
