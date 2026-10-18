import { describe, it, expect, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../context/ReachCountsContext', () => ({
  useReachCounts: () => ({ leads_pending_push: 3 }),
  ReachCountsProvider: ({ children }) => children,
}));

vi.mock('../../brand/ReachLogo', () => ({
  ReachLogo: ({ compact }) => <span data-testid={compact ? 'reach-mark' : 'reach-logo'} />,
}));

import { Sidebar } from '../Sidebar';

const ADMIN = { user: { id: 1, email: 'root@aicountly.org', role: 'super_admin' }, permissions: ['*'] };

function groupTitles() {
  return screen.getAllByRole('group').map((g) => g.getAttribute('aria-labelledby'));
}

describe('Sidebar', () => {
  it('shows the five owner groups in order for a super_admin', () => {
    renderWithAuth(<Sidebar />, { auth: ADMIN });
    expect(groupTitles()).toEqual([
      'nav-group-overview', 'nav-group-content', 'nav-group-channels', 'nav-group-growth', 'nav-group-system',
    ]);
    for (const t of ['Overview', 'Content', 'Channels', 'Growth', 'Bot & system']) {
      expect(screen.getByText(t)).toBeInTheDocument();
    }
  });

  it('lists the merged / renamed destinations and drops retired ones', () => {
    renderWithAuth(<Sidebar />, { auth: ADMIN });
    for (const label of [
      'Command centre', 'Bot plan', 'Run now', 'Ask the bot', 'Blog', 'Knowledge base', 'Release notes & guides',
      'Community', 'Video', 'Content base', 'Cover gallery', 'Social & Ads', 'Social inbox', 'Email & campaigns',
      'Landing pages', 'SEO & Search Console', 'AI visibility', 'Insights', 'Analytics & reports',
      'Leads & Engage', 'AI & Automation', 'Bot mode', 'Jobs', 'Worker', 'Audit logs', 'Roles & users',
    ]) {
      expect(screen.getByRole('link', { name: new RegExp(`^${label.replace(/[&()]/g, '\\$&')}`) })).toBeInTheDocument();
    }
    // Merged into one entry each, or retired with a redirect.
    for (const gone of [
      'Blog SEO and Indexing', 'SEO Command Centre', 'Lead Capture', 'Engage Lead Push', 'Lead Outcomes',
      'Daily Reports', 'Content Calendar', 'Daily Pack', 'New Content', 'All Content', 'Console Approvals',
      'Local Bot Reports', 'Creative Briefs', 'Distribution', 'Publishing', 'Intelligence',
    ]) {
      expect(screen.queryByText(gone)).not.toBeInTheDocument();
    }
  });

  it('opens Roles & users in Console in a new tab', () => {
    renderWithAuth(<Sidebar />, { auth: ADMIN });
    const link = screen.getByRole('link', { name: /Roles & users/ });
    expect(link).toHaveAttribute('target', '_blank');
    expect(link.getAttribute('href')).toMatch(/^https?:\/\//);
  });

  it('shows the pending-push count on Leads & Engage', () => {
    renderWithAuth(<Sidebar />, { auth: ADMIN });
    const leads = screen.getByRole('link', { name: /Leads & Engage/ });
    expect(within(leads).getByText('3')).toBeInTheDocument();
  });

  it('hides system items for an analyst', () => {
    renderWithAuth(<Sidebar />, {
      auth: {
        user: { id: 4, email: 'analyst@aicountly.org', role: 'analyst' },
        permissions: ['dashboard.view', 'analytics.view', 'blog.view', 'campaign.view'],
      },
    });
    expect(screen.getByText('Command centre')).toBeInTheDocument();
    expect(screen.getByText('Analytics & reports')).toBeInTheDocument();
    // blog.view alone keeps SEO (it absorbed Blog SEO and Indexing, which was blog.view).
    expect(screen.getByText('SEO & Search Console')).toBeInTheDocument();
    expect(screen.queryByText('Bot & system')).not.toBeInTheDocument();
    expect(screen.queryByText('Audit logs')).not.toBeInTheDocument();
    expect(screen.queryByText('Social & Ads')).not.toBeInTheDocument();
  });

  it('hides entire groups whose items are all denied', () => {
    renderWithAuth(<Sidebar />, {
      auth: { user: { id: 5, email: 'viewer@aicountly.org', role: 'viewer' }, permissions: ['dashboard.view'] },
    });
    expect(screen.getByText('Overview')).toBeInTheDocument();
    expect(screen.queryByText('Content')).not.toBeInTheDocument();
    expect(screen.queryByText('Bot & system')).not.toBeInTheDocument();
  });

  it('shows Social & Ads with either social.view or distribution.read', () => {
    renderWithAuth(<Sidebar />, {
      auth: { user: { id: 7, role: 'x' }, permissions: ['distribution.read'] },
    });
    expect(screen.getByText('Social & Ads')).toBeInTheDocument();
    expect(screen.getByText('Email & campaigns')).toBeInTheDocument();
  });

  it('shows a Console-assigned content_reviewer only what that role may use', () => {
    renderWithAuth(<Sidebar />, {
      auth: {
        user: { id: 6, email: 'reviewer@aicountly.org', role: 'content_reviewer', role_slug: 'content_reviewer' },
        permissions: [
          'dashboard.view', 'blog.view', 'blog.approve', 'campaign.view', 'approval.view',
          'approval.decide', 'lead.view', 'bot.view', 'content.view', 'content.review',
          'daily_pack.view', 'publishing.view', 'seo.view', 'knowledge.view', 'community.view',
        ],
      },
    });
    expect(screen.getByTestId('sidebar-role')).toHaveTextContent('Content Reviewer');
    expect(screen.getByText('Blog')).toBeInTheDocument();
    expect(screen.getByText('Publishing log')).toBeInTheDocument();
    expect(screen.queryByText('AI providers & usage')).not.toBeInTheDocument();
    expect(screen.queryByText('Portal settings')).not.toBeInTheDocument();
    expect(screen.queryByText('Bot mode')).not.toBeInTheDocument();
    expect(screen.queryByText('Audit logs')).not.toBeInTheDocument();
    expect(screen.queryByText('Jobs')).not.toBeInTheDocument();
    expect(screen.queryByText('Analytics & reports')).not.toBeInTheDocument();
  });

  it('rail variant keeps every label as the accessible name and hides the role line', () => {
    renderWithAuth(<Sidebar variant="rail" onToggleRail={() => {}} railToggleLabel="Expand sidebar" />, { auth: ADMIN });
    expect(screen.getByTestId('reach-sidebar')).toHaveAttribute('data-variant', 'rail');
    expect(screen.getByRole('link', { name: 'Leads & Engage (3)' })).toHaveAttribute('title', 'Leads & Engage (3)');
    expect(screen.getByRole('button', { name: 'Expand sidebar' })).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByTestId('sidebar-role')).not.toBeInTheDocument();
    expect(screen.getByTestId('reach-mark')).toBeInTheDocument();
  });

  it('a closed drawer is inert and hidden from assistive tech', () => {
    renderWithAuth(<Sidebar variant="drawer" open={false} onClose={() => {}} />, { auth: ADMIN });
    const aside = screen.getByTestId('reach-sidebar');
    expect(aside).toHaveAttribute('aria-hidden', 'true');
    expect(aside).toHaveAttribute('inert');
  });
});
