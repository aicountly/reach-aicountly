import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { BlogOverviewPage } from '../BlogOverviewPage.jsx';

const getOverview = vi.fn();

vi.mock('../../../services/blogCommandCentreService', () => ({
  blogCommandCentreService: { getOverview: (...args) => getOverview(...args) },
}));

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['blog.view', 'content.view', 'publishing.view'],
  },
  route: '/blog-command-centre',
};

function overview(overrides = {}) {
  return {
    workflow_counts: {},
    content: { blog_total: 0 },
    publishing: { queued: 0, in_flight: 0, failed: 0, failed_recent: 0, published: 0, verified: 0, recent_days: 7 },
    work_blocks: {},
    connectors: {},
    ai_providers: {},
    optimizer: {},
    ...overrides,
  };
}

function render() {
  return renderWithAuth(
    <Routes>
      <Route path="/blog-command-centre" element={<BlogOverviewPage />} />
    </Routes>,
    ctx,
  );
}

/** The card is a link; find the anchor wrapping a card title. */
function cardLink(title) {
  return screen.getByText(title).closest('a');
}

function cardBadge(title) {
  return screen.getByText(title).closest('.bcc-card__header').querySelector('.badge');
}

describe('BlogOverviewPage — Publishing card', () => {
  beforeEach(() => getOverview.mockReset());

  it('links to the deployment list filtered to failures, not to Ready to Publish', async () => {
    getOverview.mockResolvedValue(overview({
      publishing: { in_flight: 0, failed: 22, failed_recent: 22, published: 7, verified: 0, recent_days: 7 },
    }));
    render();

    await waitFor(() => expect(screen.getByText('Publishing')).toBeInTheDocument());
    // Ready to Publish lists approved drafts and cannot show a deployment.
    expect(cardLink('Publishing')).toHaveAttribute(
      'href',
      '/blog-command-centre/publishing/deployments?status=failed,blocked',
    );
  });

  it('reads ERROR while failures are recent', async () => {
    getOverview.mockResolvedValue(overview({
      publishing: { in_flight: 0, failed: 3, failed_recent: 3, published: 1, verified: 0, recent_days: 7 },
    }));
    render();

    await waitFor(() => expect(cardBadge('Publishing')).toHaveTextContent('ERROR'));
  });

  it('drops to WARN once the failures are older than the window', async () => {
    getOverview.mockResolvedValue(overview({
      publishing: { in_flight: 0, failed: 3, failed_recent: 0, published: 1, verified: 0, recent_days: 7 },
    }));
    render();

    // A lifetime failed count used to pin this card to ERROR forever.
    await waitFor(() => expect(cardBadge('Publishing')).toHaveTextContent('WARN'));
  });

  it('reads OK when work is in flight and nothing has failed', async () => {
    getOverview.mockResolvedValue(overview({
      publishing: { in_flight: 4, failed: 0, failed_recent: 0, published: 0, verified: 0, recent_days: 7 },
    }));
    render();

    await waitFor(() => expect(cardBadge('Publishing')).toHaveTextContent('OK'));
    expect(screen.getByText('in flight')).toBeInTheDocument();
  });
});

describe('BlogOverviewPage — Production card', () => {
  beforeEach(() => getOverview.mockReset());

  it('reads IDLE, not NO DATA, when the library has moved past production', async () => {
    getOverview.mockResolvedValue(overview({
      workflow_counts: { published: 7, publish_queued: 5 },
      content: { blog_total: 12 },
    }));
    render();

    await waitFor(() => expect(cardBadge('Production')).toHaveTextContent('IDLE'));
    expect(screen.getByText(/all 12 blog items are past this stage/i)).toBeInTheDocument();
  });

  it('sends you to the full library rather than an empty Drafts filter', async () => {
    getOverview.mockResolvedValue(overview({
      workflow_counts: { published: 7 },
      content: { blog_total: 7 },
    }));
    render();

    await waitFor(() => expect(screen.getByText('Production')).toBeInTheDocument());
    expect(cardLink('Production')).toHaveAttribute('href', '/blog-command-centre/pipeline/versions');
  });

  it('still reads NO DATA when no blog content exists at all', async () => {
    getOverview.mockResolvedValue(overview());
    render();

    await waitFor(() => expect(cardBadge('Production')).toHaveTextContent('NO DATA'));
  });

  it('sends you to drafts when there really are drafts', async () => {
    getOverview.mockResolvedValue(overview({
      workflow_counts: { draft: 3 },
      content: { blog_total: 3 },
    }));
    render();

    await waitFor(() => expect(cardBadge('Production')).toHaveTextContent('OK'));
    expect(cardLink('Production')).toHaveAttribute('href', '/blog-command-centre/pipeline/drafts');
  });
});
