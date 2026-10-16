import { CONSOLE_WEB_URL } from '../../services/consoleAuth';

export const ROLES_MANAGED_TEXT = 'Roles are assigned in Console → Roles & Access';

/**
 * Reach does not edit staff roles: Console (console.aicountly.org → Roles &
 * Access) assigns them and Reach mirrors the role at sign-in / token refresh.
 * Per-user permission overrides remain portal-local.
 */
export function RolesManagedNote() {
  return (
    <p className="text-xs text-muted" style={{ margin: '0 0 1rem' }}>
      {ROLES_MANAGED_TEXT} (
      <a href={CONSOLE_WEB_URL} target="_blank" rel="noreferrer">
        {CONSOLE_WEB_URL.replace(/^https?:\/\//, '')}
      </a>
      ).
    </p>
  );
}
