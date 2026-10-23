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
      // Blog-only, like the counts on the card.
      '/blog-command-centre/publishing/deployments?status=failed%2Cblocked&content_type=blog',
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

describe('BlogOverviewPage — metric drill-down', () => {
  beforeEach(() => getOverview.mockReset());

  const metricLink = (name) => screen.getByRole('link', { name });

  it('links each count to the records it added up', async () => {
    getOverview.mockResolvedValue(overview({
      workflow_counts: { brief_ready: 2, outline_ready: 1, draft: 3, internal_review: 1, approved: 4, rejected: 2 },
      content: { blog_total: 13 },
      publishing: { in_flight: 2, failed: 1, failed_recent: 0, published: 5, verified: 0, recent_days: 7 },
      optimizer: { enabled: true, last_run: { id: 9, run_for_date: '2026-10-22', status: 'completed', candidates_scored: 12, decisions_created: 4 } },
    }));
    render();

    await waitFor(() => expect(screen.getByText('Production')).toBeInTheDocument());
    expect(metricLink('Production: 3 briefs/outlines')).toHaveAttribute('href',
      '/blog-command-centre/pipeline/briefs?workflow_status=idea%2Croadmap_planned%2Cbrief_draft%2Cbrief_ready%2Coutline_draft%2Coutline_ready');
    expect(metricLink('Production: 3 drafts')).toHaveAttribute('href',
      '/blog-command-centre/pipeline/drafts?workflow_status=draft%2Cdraft_generating%2Cfact_verifying%2Cfact_verified');
    expect(metricLink('Production: 1 awaiting review')).toHaveAttribute('href',
      '/blog-command-centre/pipeline/editorial?workflow_status=seo_review%2Cinternal_review%2Cchanges_requested');
    expect(metricLink('Quality: 4 approved')).toHaveAttribute('href', '/content?content_type=blog&workflow_status=approved');
    expect(metricLink('Quality: 2 rejected')).toHaveAttribute('href', '/content?content_type=blog&workflow_status=rejected');
    expect(metricLink('Publishing: 5 published')).toHaveAttribute('href',
      '/blog-command-centre/publishing/deployments?status=published&content_type=blog');
    expect(metricLink('Publishing: 1 failed')).toHaveAttribute('href',
      '/blog-command-centre/publishing/deployments?status=failed%2Cblocked&content_type=blog');
    expect(metricLink('Roadmap: 4 decisions')).toHaveAttribute('href', '/blog-command-centre/roadmap/optimizer?run_id=9');
    expect(metricLink('Roadmap: 12 scored')).toHaveAttribute('href', '/blog-command-centre/roadmap/scored?scored_for_date=2026-10-22');
  });

  it('does not link a zero', async () => {
    getOverview.mockResolvedValue(overview({ content: { blog_total: 0 } }));
    render();

    await waitFor(() => expect(screen.getByText('Production')).toBeInTheDocument());
    expect(screen.queryByRole('link', { name: /drafts/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /failed/ })).not.toBeInTheDocument();
  });
});
