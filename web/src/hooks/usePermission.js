import { useAuth } from '../context/AuthContext';

/**
 * Returns { has(perm), hasAny(perms), hasAll(perms) } based on the current
 * user's effective permission set. Wildcard "*" grants everything.
 * A group wildcard like "blog.*" grants all permissions in that group.
 */
export function usePermission() {
  const { hasPermission } = useAuth();
  return {
    has: (perm) => hasPermission(perm),
    hasAny: (perms) => (perms || []).some((p) => hasPermission(p)),
    hasAll: (perms) => (perms || []).every((p) => hasPermission(p)),
  };
}
