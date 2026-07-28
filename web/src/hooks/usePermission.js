import { useCallback, useMemo } from 'react';
import { useAuth } from '../context/AuthContext';

/**
 * Returns { has(perm), hasAny(perms), hasAll(perms) } based on the current
 * user's effective permission set. Wildcard "*" grants everything.
 * A group wildcard like "blog.*" grants all permissions in that group.
 *
 * Callbacks are referentially stable for a given auth session so they are
 * safe to use in effect dependency arrays.
 */
export function usePermission() {
  const { hasPermission } = useAuth();

  const has = useCallback((perm) => hasPermission(perm), [hasPermission]);
  const hasAny = useCallback(
    (perms) => (perms || []).some((p) => hasPermission(p)),
    [hasPermission],
  );
  const hasAll = useCallback(
    (perms) => (perms || []).every((p) => hasPermission(p)),
    [hasPermission],
  );

  return useMemo(() => ({ has, hasAny, hasAll }), [has, hasAny, hasAll]);
}
