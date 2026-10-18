import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import { Route, Routes, useLocation } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../context/ReachCountsContext', () => ({
  useReachCounts: () => ({}),
  ReachCountsProvider: ({ children }) => children,
}));
vi.mock('../../bot/BotModeBadge', () => ({ BotModeBadge: () => <span data-testid="bot-mode-badge">Bot: Auto</span> }));
vi.mock('../AppLauncher', () => ({ AppLauncher: () => <span data-testid="apps" /> }));
vi.mock('../NotificationBell', () => ({ NotificationBell: () => <span data-testid="bell" /> }));
vi.mock('../../../services/adminService', () => ({
  adminService: {
    automationSettings: vi.fn(() => Promise.resolve([
      { key: 'social.socialapi.api_key', label: 'social-api.ai API key', group: 'Social', help: 'Starts with sapi_key_' },
    ])),
  },
}));

import { ReachLayout } from '../ReachLayout';
import { SIDEBAR_COLLAPSED_KEY } from '../../../hooks/useShellLayout';

const ADMIN = { user: { id: 1, name: 'Root Admin', email: 'root@aicountly.org', role: 'super_admin' }, permissions: ['*'] };

function Where() {
  const l = useLocation();
  return <div data-testid="where">{l.pathname}{l.search}{l.hash}</div>;
}

function renderShell(route = '/') {
  return renderWithAuth(
    <Routes>
      <Route element={<ReachLayout />}>
        <Route path="*" element={<Where />} />
      </Route>
    </Routes>,
    { auth: ADMIN, route },
  );
}

const original = window.matchMedia;
function setViewport(mode) {
  window.matchMedia = (q) => ({
    matches: (mode === 'phone' && q.includes('max-width: 767.98px') && !q.includes('min-width'))
      || (mode === 'tablet' && q.includes('min-width: 768px')),
    media: q,
    addEventListener: () => {},
    removeEventListener: () => {},
  });
}

beforeEach(() => { setViewport('desktop'); });
afterEach(() => { window.matchMedia = original; });

describe('ReachLayout — responsive shell', () => {
  it('desktop: full sidebar, no hamburger; collapse to the rail is remembered', () => {
    renderShell();
    expect(screen.getByTestId('reach-sidebar')).toHaveAttribute('data-variant', 'full');
    expect(screen.queryByTestId('nav-menu-button')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Collapse sidebar' }));
    expect(screen.getByTestId('reach-sidebar')).toHaveAttribute('data-variant', 'rail');
    expect(localStorage.getItem(SIDEBAR_COLLAPSED_KEY)).toBe('1');
  });

  it('desktop: starts collapsed when the preference says so', () => {
    localStorage.setItem(SIDEBAR_COLLAPSED_KEY, '1');
    renderShell();
    expect(screen.getByTestId('reach-sidebar')).toHaveAttribute('data-variant', 'rail');
    expect(screen.getByRole('button', { name: 'Expand sidebar' })).toBeInTheDocument();
  });

  it('survives storage that throws', () => {
    const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked'); });
    const spy2 = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked'); });
    renderShell();
    fireEvent.click(screen.getByRole('button', { name: 'Collapse sidebar' }));
    expect(screen.getByTestId('reach-sidebar')).toHaveAttribute('data-variant', 'rail');
    spy.mockRestore(); spy2.mockRestore();
  });

  it('tablet: icon rail by default, the menu button expands it', () => {
    setViewport('tablet');
    renderShell();
    expect(screen.getByTestId('reach-sidebar')).toHaveAttribute('data-variant', 'rail');
    fireEvent.click(screen.getByTestId('nav-menu-button'));
    expect(screen.getByTestId('reach-sidebar')).toHaveAttribute('data-variant', 'full');
  });

  it('phone: hamburger opens the drawer as a dialog; Escape and the backdrop close it', async () => {
    setViewport('phone');
    renderShell();
    const aside = screen.getByTestId('reach-sidebar');
    const menu = screen.getByTestId('nav-menu-button');
    expect(aside).toHaveAttribute('data-variant', 'drawer');
    expect(aside).toHaveAttribute('aria-hidden', 'true');
    expect(menu).toHaveAttribute('aria-expanded', 'false');

    fireEvent.click(menu);
    expect(screen.getByRole('dialog', { name: 'Navigation' })).toBe(aside);
    expect(aside).not.toHaveAttribute('aria-hidden');
    expect(menu).toHaveAttribute('aria-expanded', 'true');
    await waitFor(() => expect(screen.getByRole('button', { name: 'Close navigation' })).toHaveFocus());

    fireEvent.keyDown(aside, { key: 'Escape' });
    expect(aside).toHaveAttribute('aria-hidden', 'true');
    expect(menu).toHaveFocus();

    fireEvent.click(menu);
    fireEvent.click(screen.getByTestId('nav-backdrop'));
    expect(aside).toHaveAttribute('aria-hidden', 'true');
  });

  it('phone: following a link closes the drawer', () => {
    setViewport('phone');
    renderShell();
    fireEvent.click(screen.getByTestId('nav-menu-button'));
    fireEvent.click(screen.getByRole('link', { name: /^Jobs/ }));
    expect(screen.getByTestId('where')).toHaveTextContent('/admin/jobs');
    expect(screen.getByTestId('reach-sidebar')).toHaveAttribute('aria-hidden', 'true');
  });

  it('header: user menu shows who is signed in and offers sign out', () => {
    renderShell();
    fireEvent.click(screen.getByTestId('user-menu-button'));
    expect(screen.getByText('root@aicountly.org')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Sign out/ })).toBeInTheDocument();
  });
});

describe('ReachLayout — command palette', () => {
  it('Ctrl-K opens it; typing filters; Enter jumps to the page', async () => {
    renderShell('/');
    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    const input = await screen.findByRole('combobox', { name: 'Search pages and settings' });
    await waitFor(() => expect(input).toHaveFocus());
    fireEvent.change(input, { target: { value: 'social cal' } });
    expect(screen.getAllByRole('option')[0]).toHaveTextContent('Social calendar & queue');
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(screen.getByTestId('where')).toHaveTextContent('/social/calendar');
    expect(screen.queryByTestId('command-palette')).not.toBeInTheDocument();
  });

  it('finds an individual AI & Automation setting and deep-links it', async () => {
    renderShell('/');
    fireEvent.click(screen.getByTestId('open-command-palette'));
    const input = await screen.findByRole('combobox');
    await act(async () => {});
    fireEvent.change(input, { target: { value: 'sapi_key' } });
    const opt = await screen.findByRole('option', { name: /social-api\.ai API key/ });
    fireEvent.click(opt);
    expect(screen.getByTestId('where')).toHaveTextContent('/admin/ai-automation?q=social.socialapi.api_key#social');
  });

  it('arrow keys move the active option; Escape closes and says nothing matched for junk', async () => {
    renderShell('/');
    fireEvent.keyDown(window, { key: 'k', metaKey: true });
    const input = await screen.findByRole('combobox');
    const first = screen.getAllByRole('option')[0];
    expect(first).toHaveAttribute('aria-selected', 'true');
    fireEvent.keyDown(input, { key: 'ArrowDown' });
    expect(screen.getAllByRole('option')[1]).toHaveAttribute('aria-selected', 'true');
    expect(input.getAttribute('aria-activedescendant')).toBe(screen.getAllByRole('option')[1].id);
    fireEvent.change(input, { target: { value: 'qqqqzzzz' } });
    expect(screen.getByRole('status')).toHaveTextContent('Nothing matches');
    fireEvent.keyDown(input, { key: 'Escape' });
    expect(screen.queryByTestId('command-palette')).not.toBeInTheDocument();
  });
});
