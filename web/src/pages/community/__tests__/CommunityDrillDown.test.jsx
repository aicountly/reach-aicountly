import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => {
  const client = { get: vi.fn(), getPage: vi.fn(), post: vi.fn() };
  return { default: client, api: client };
});
import api from '../../../services/api';
import CommunityOverviewPage from '../CommunityOverviewPage';
import CommunityAnalyticsPage from '../CommunityAnalyticsPage';
import CommunitySettingsPage from '../CommunitySettingsPage';
import CommunityModerationQueuePage from '../CommunityModerationQueuePage';
import AgentOperationsPage from '../AgentOperationsPage';
import OfficialAnswerListPage from '../OfficialAnswerListPage';

/**
 * Community counts open the records behind them, and the lists they open
 * apply the filter from the URL.
 */
const ctx = (route) => ({
  route,
  auth: { user: { id: 1, role: 'super_admin' }, permissions: ['*'] },
});

const page = (rows, meta = {}) => ({
  data: rows,
  meta: { current_page: 1, per_page: 25, total: rows.length, last_page: rows.length ? 1 : 0, ...meta },
});

beforeEach(() => {
  api.get.mockReset();
  api.getPage.mockReset();
});

describe('Overview', () => {
  it('by-status rows open the list filtered to that status, and zeros do not link', async () => {
    api.get.mockResolvedValueOnce({
      questions_by_status: { intake: 7, triaged: 0 },
      answers_by_status: { editorial_review: 2 },
      published_answers: 0,
      pending_approval: 2,
      open_moderation_flags: 0,
    });
    renderWithAuth(<CommunityOverviewPage />, ctx('/community'));

    expect(await screen.findByRole('link', { name: '7 intake' })).toHaveAttribute('href', '/community/questions?status=intake');
    expect(screen.getByRole('link', { name: '2 editorial_review' })).toHaveAttribute('href', '/community/answers?status=editorial_review');
    expect(screen.queryByRole('link', { name: '0 triaged' })).not.toBeInTheDocument();

    const hrefs = screen.getAllByRole('link').map((a) => a.getAttribute('href'));
    expect(hrefs).not.toContain('/community/answers?status=published');
    expect(hrefs).not.toContain('/community/moderation');
  });
});

describe('Analytics', () => {
  it('tiles link to the answers and moderation queue', async () => {
    api.get.mockImplementation((path) => Promise.resolve(path.endsWith('overview')
      ? { published_answers: 4, pending_approval: 1, open_moderation_flags: 3 }
      : []));
    renderWithAuth(<CommunityAnalyticsPage />, ctx('/community/analytics'));

    expect(await screen.findByRole('link', { name: 'View →' })).toHaveAttribute('href', '/community/answers?status=published');
    expect(screen.getByRole('link', { name: 'Review →' })).toHaveAttribute('href', '/community/answers?status=awaiting_approval');
    expect(screen.getByRole('link', { name: 'Resolve →' })).toHaveAttribute('href', '/community/moderation');
  });
});

describe('Settings', () => {
  it('a space opens its questions in the inbox', async () => {
    api.get.mockResolvedValueOnce([{ id: 3, slug: 'gst-help', title: 'GST help', is_active: true }]);
    renderWithAuth(<CommunitySettingsPage />, ctx('/community/settings'));

    expect(await screen.findByRole('link', { name: 'GST help' })).toHaveAttribute('href', '/community/questions?space_id=3');
  });
});

describe('Moderation queue', () => {
  it('applies finding type and severity from the URL', async () => {
    api.getPage.mockResolvedValue(page([]));
    renderWithAuth(<CommunityModerationQueuePage />, ctx('/community/moderation?finding_type=spam&severity=critical'));

    await waitFor(() => expect(api.getPage).toHaveBeenCalled());
    expect(api.getPage).toHaveBeenLastCalledWith('v1/community/moderation/queue', expect.objectContaining({
      finding_type: 'spam', severity: 'critical', page: 1,
    }));
    expect(await screen.findByRole('button', { name: /clear finding type spam filter/i })).toBeInTheDocument();
  });

  it('a finding opens its answer, and its type narrows the queue', async () => {
    api.getPage.mockResolvedValue(page([{
      id: '5', answer_version_id: '10', version_number: '2', answer_uuid: 'ans-uuid',
      finding_type: 'unsafe_links', severity: 'warning', details: '{}', created_at: '2026-07-11',
    }]));
    renderWithAuth(<CommunityModerationQueuePage />, ctx('/community/moderation'));

    expect(await screen.findByRole('link', { name: /10/ })).toHaveAttribute('href', '/community/answers/ans-uuid');
    fireEvent.click(screen.getByRole('button', { name: 'unsafe_links' }));
    await waitFor(() => expect(api.getPage).toHaveBeenLastCalledWith(
      'v1/community/moderation/queue', expect.objectContaining({ finding_type: 'unsafe_links' }),
    ));
  });
});

describe('Agent operations', () => {
  const status = {
    window_open: true,
    identities: [{
      slug: 'desk-gst', display_name: 'GST desk', role: 'answerer',
      actions: [
        { action: 'answer', daily_cap: 10, used_today: 3, window_gated: true },
        { action: 'comment', daily_cap: 5, used_today: 0, window_gated: true },
      ],
    }],
  };

  it('"Used today" opens the runs it counted', async () => {
    api.get.mockImplementation((path) => Promise.resolve(path.endsWith('status') ? status : { runs: [] }));
    renderWithAuth(<AgentOperationsPage />, ctx('/community/agents'));

    expect(await screen.findByRole('link', { name: '3 answer runs by desk-gst today' }))
      .toHaveAttribute('href', '/community/agents?identity=desk-gst&action=answer&status=success&since=today');
    expect(screen.queryByRole('link', { name: /comment runs/ })).not.toBeInTheDocument();
  });

  it('applies identity, action, status and day from the URL to the runs list', async () => {
    api.get.mockImplementation((path) => Promise.resolve(path.endsWith('status') ? status : { runs: [] }));
    renderWithAuth(<AgentOperationsPage />, ctx('/community/agents?identity=desk-gst&action=answer&status=success&since=today'));

    await waitFor(() => expect(api.get).toHaveBeenCalledWith('v1/community/agents/runs', {
      status: 'success', identity: 'desk-gst', action: 'answer', since: 'today',
    }));
    expect(await screen.findByRole('button', { name: /clear identity: desk-gst filter/i })).toBeInTheDocument();
  });
});

describe('Official answers', () => {
  it('keeps a linked status selectable even when it is not a listed option', async () => {
    api.getPage.mockResolvedValue(page([]));
    renderWithAuth(<OfficialAnswerListPage />, ctx('/community/answers?status=archived'));

    await waitFor(() => expect(api.getPage).toHaveBeenCalledWith('v1/community/answers', expect.objectContaining({ status: 'archived' })));
    expect(screen.getByDisplayValue('archived')).toBeInTheDocument();
  });
});
