import { useNavigate } from 'react-router-dom';
import { LogOut, User } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import { BotModeBadge } from '../bot/BotModeBadge';
import { ReachLogo } from '../brand/ReachLogo';
import { ROUTES } from '../../constants/routes';

export function Header() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const doLogout = async () => {
    await logout();
    navigate(ROUTES.LOGIN);
  };
  return (
    <header style={{
      height: 'var(--header-height)',
      background: 'var(--color-surface)',
      borderBottom: '1px solid var(--color-border)',
      display: 'flex', alignItems: 'center', justifyContent: 'space-between',
      padding: '0 1.25rem', position: 'sticky', top: 0, zIndex: 100,
    }}>
      <div className="flex items-center gap-3">
        <ReachLogo height={28} />
        <span className="text-sm text-muted" style={{ marginLeft: '0.25rem' }}>
          Marketing operations portal
        </span>
      </div>
      <div className="flex items-center gap-3">
        <BotModeBadge />
        <div className="flex items-center gap-2">
          <div style={{
            width: 30, height: 30, borderRadius: '50%',
            background: 'var(--color-primary-light)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            <User size={14} style={{ color: 'var(--color-primary)' }} />
          </div>
          <span className="text-sm font-semibold">{user?.name || user?.email || ''}</span>
        </div>
        <span style={{ color: 'var(--color-border)' }} aria-hidden>|</span>
        <button
          type="button"
          onClick={doLogout}
          className="btn btn-secondary"
          style={{ padding: '0.3rem 0.6rem', fontSize: '0.78rem' }}
        >
          <LogOut size={12} /> Sign out
        </button>
      </div>
    </header>
  );
}
