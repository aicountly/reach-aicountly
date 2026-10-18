import { Menu, Search } from 'lucide-react';
import { BotModeBadge } from '../bot/BotModeBadge';
import { ReachLogo } from '../brand/ReachLogo';
import { AppLauncher } from './AppLauncher';
import { NotificationBell } from './NotificationBell';
import { UserMenu } from './UserMenu';
import { SIDEBAR_ID } from './Sidebar';

function isMac() {
  try {
    return /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
  } catch {
    return false;
  }
}

/**
 * Top bar. Wraps down gracefully: the search box shrinks to an icon, the
 * user's name and the "Bot:" word drop out, and on phones the hamburger and
 * the Reach mark take the place of the (hidden) sidebar.
 */
export function Header({ mode = 'desktop', navOpen = false, onOpenNav, onOpenPalette, menuButtonRef }) {
  const shortcut = isMac() ? '⌘K' : 'Ctrl K';
  const showMenu = mode !== 'desktop';
  return (
    <header className="reach-header" data-mode={mode}>
      <div className="reach-header__start">
        {showMenu && (
          <button
            ref={menuButtonRef}
            type="button"
            className="reach-icon-btn reach-header__menu"
            onClick={onOpenNav}
            aria-label="Navigation menu"
            aria-controls={SIDEBAR_ID}
            aria-expanded={navOpen}
            data-testid="nav-menu-button"
          >
            <Menu size={20} aria-hidden="true" />
          </button>
        )}
        {mode === 'phone' && <span className="reach-header__mark"><ReachLogo height={28} compact /></span>}
        <button
          type="button"
          className="reach-header__search"
          onClick={onOpenPalette}
          aria-label={`Search pages and settings (${shortcut})`}
          aria-haspopup="dialog"
          data-testid="open-command-palette"
        >
          <Search size={16} aria-hidden="true" />
          <span className="reach-header__search-text">Search pages and settings…</span>
          <kbd className="reach-kbd reach-header__search-kbd">{shortcut}</kbd>
        </button>
      </div>
      <div className="reach-header__end">
        <AppLauncher />
        <NotificationBell />
        <span className="reach-header__bot"><BotModeBadge /></span>
        <UserMenu />
      </div>
    </header>
  );
}
