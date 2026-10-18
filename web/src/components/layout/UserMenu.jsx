import { useEffect, useId, useRef, useState } from 'react';
import { ChevronDown, LogOut, User } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { ROLES_MANAGED_TEXT } from '../auth/RolesManagedNote';

/**
 * Avatar button + menu (who is signed in, role, sign out). The name is shown
 * next to the avatar on wide screens only; on phones the avatar alone keeps
 * the header on one line.
 */
export function UserMenu() {
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);
  const wrapRef = useRef(null);
  const buttonRef = useRef(null);
  const menuId = useId();
  const name = user?.name || user?.email || 'Signed in';
  const role = user?.role_name || (user?.role_slug || user?.role || '').replace(/_/g, ' ');

  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') {
        setOpen(false);
        buttonRef.current?.focus();
      }
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className="reach-user" ref={wrapRef}>
      <button
        ref={buttonRef}
        type="button"
        className="reach-user__button"
        aria-haspopup="true"
        aria-expanded={open}
        aria-controls={menuId}
        aria-label={`Account: ${name}`}
        onClick={() => setOpen((o) => !o)}
        data-testid="user-menu-button"
      >
        <span className="reach-user__avatar" aria-hidden="true"><User size={14} /></span>
        <span className="reach-user__name">{name}</span>
        <ChevronDown size={14} className="reach-user__chev" aria-hidden="true" />
      </button>
      {open && (
        <div id={menuId} className="reach-popover reach-user__menu">
          <div className="reach-user__who">
            <strong>{name}</strong>
            {user?.email && user.email !== name && <span className="text-xs text-muted">{user.email}</span>}
            {role && <span className="text-xs text-muted" title={ROLES_MANAGED_TEXT}>{role}</span>}
          </div>
          <button
            type="button"
            className="reach-user__item"
            onClick={async () => { setOpen(false); await logout(); }}
          >
            <LogOut size={14} aria-hidden="true" /> Sign out
          </button>
        </div>
      )}
    </div>
  );
}
