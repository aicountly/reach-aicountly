import { usePermission } from '../../hooks/usePermission';

/**
 * Conditionally renders children only if the current user has the given
 * permission. Falls back to `fallback` prop (defaults to null) when denied.
 *
 * Backend enforcement remains authoritative — this is a UX helper.
 */
export function RequirePermission({ permission, fallback = null, children }) {
  const { has } = usePermission();
  if (!has(permission)) return fallback;
  return children;
}
