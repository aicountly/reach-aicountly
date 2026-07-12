import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../context/ReachCountsContext', () => ({
  useReachCounts: () => ({}),
  ReachCountsProvider: ({ children }) => children,
}));

vi.mock('../../bot/BotModeBadge', () => ({
  BotModeBadge: () => <span data-testid="bot-mode-badge" />,
}));

vi.mock('../../brand/ReachLogo', () => ({
  ReachLogo: () => <span data-testid="reach-logo" />,
}));

import { Sidebar } from '../Sidebar';

describe('Sidebar', () => {
  it('renders every nav item for a super_admin (wildcard permission)', () => {
    renderWithAuth(<Sidebar />, {
      auth: {
        user: { id: 1, email: 'root@aicountly.org', role: 'super_admin' },
        permissions: ['*'],
      },
    });
    expect(screen.getByText('Blog Management')).toBeInTheDocument();
    expect(screen.getByText('Bot Queue')).toBeInTheDocument();
    expect(screen.getByText('Audit Logs')).toBeInTheDocument();
    expect(screen.getByText('Job Monitor')).toBeInTheDocument();
    expect(screen.getByText('Settings')).toBeInTheDocument();
  });

  it('hides Marketing Bot and Administration items for an analyst', () => {
    renderWithAuth(<Sidebar />, {
      auth: {
        user: { id: 4, email: 'analyst@aicountly.org', role: 'analyst' },
        permissions: ['dashboard.view', 'analytics.view', 'blog.view', 'campaign.view'],
      },
    });
    expect(screen.getByText('Dashboard')).toBeInTheDocument();
    expect(screen.getByText('Analytics')).toBeInTheDocument();
    expect(screen.getByText('Blog Management')).toBeInTheDocument();
    expect(screen.queryByText('Bot Queue')).not.toBeInTheDocument();
    expect(screen.queryByText('Audit Logs')).not.toBeInTheDocument();
    expect(screen.queryByText('Settings')).not.toBeInTheDocument();
    expect(screen.queryByText('Console Approvals')).not.toBeInTheDocument();
  });

  it('hides entire sections whose items are all denied', () => {
    renderWithAuth(<Sidebar />, {
      auth: {
        user: { id: 5, email: 'viewer@aicountly.org', role: 'viewer' },
        permissions: ['dashboard.view'],
      },
    });
    // Only Marketing section should remain (Dashboard is the only permitted item).
    expect(screen.getByText('Marketing')).toBeInTheDocument();
    expect(screen.queryByText('Marketing Bot')).not.toBeInTheDocument();
    expect(screen.queryByText('Administration')).not.toBeInTheDocument();
  });
});
