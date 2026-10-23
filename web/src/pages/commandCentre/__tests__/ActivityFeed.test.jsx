import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/dashboardService', () => ({
  dashboardService: { commandCentreActivity: vi.fn() },
}));
vi.mock('../../../services/api', () => ({ api: { get: vi.fn() }, default: {} }));

import { dashboardService } from '../../../services/dashboardService';
import { ActivityFeed } from '../Sections';

const counters = {
  blog_published: { label: 'Blog published', h24: 1, d7: 3, d30: 34 },
  community_answer_published: { label: 'Community answer published', h24: 5, d7: 30, d30: 58 },
  vetting_rejected: { label: 'Rejected by vetting', h24: 0, d7: 1, d30: 41 },
  job_failed: { label: 'Job failed', h24: 0, d7: 12, d30: 2236 },
  kb_published: { label: 'Help article published', h24: 0, d7: 0, d30: 0 },
};
const section = { status: 'ok', built_at: '2026-10-23T05:00:00Z', counters, events: [] };

const answer = (i) => ({
  kind: 'community_answer_published', id: i, label: 'Community answer published', title: `How do I file GSTR-${i}?`,
  detail: 'gst', at: '2026-10-22 10:00:00+00', link: '/community/automated', public_url: `https://aicountly.com/community/q-${i}`, tone: 'positive',
});
const page = (over) => ({ kind: 'all', label: 'All', window: '7d', total: 0, page: 1, per_page: 25, grouped: false, has_more: false, items: [], counters, ...over });

const ctx = { auth: { user: { id: 1, email: 'o@x', role: 'super_admin' }, permissions: ['*'] } };

beforeEach(() => {
  vi.clearAllMocks();
  dashboardService.commandCentreActivity.mockResolvedValue(page({ total: 34, items: [answer(1)] }));
});

describe('What the bot did', () => {
  it('lists the items behind "All" with the time in IST — PostgreSQL "+00" included', async () => {
    renderWithAuth(<ActivityFeed section={section} />, ctx);

    const item = await screen.findByText('How do I file GSTR-1?');
    expect(dashboardService.commandCentreActivity).toHaveBeenCalledWith({ kind: 'all', window: '7d', page: 1, per_page: 25 });
    expect(screen.queryByText('Nothing in this window.')).not.toBeInTheDocument();
    expect(item.closest('a')).toHaveAttribute('href', '/community/automated');
    const row = item.closest('li');
    expect(within(row).getByText('22-10-2026 15:30 IST')).toBeInTheDocument();
    expect(within(row).getByRole('link', { name: /aicountly\.com/ })).toHaveAttribute('href', 'https://aicountly.com/community/q-1');
    expect(screen.getByRole('button', { name: /All/ })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.queryByRole('button', { name: /Help article published/ })).not.toBeInTheDocument();
  });

  it('opens a tile, pages through every item and moves focus to the new rows', async () => {
    const user = userEvent.setup();
    dashboardService.commandCentreActivity.mockImplementation(({ kind, page: p }) => Promise.resolve(kind !== 'community_answer_published'
      ? page({ total: 34, items: [] })
      : page({ kind, label: 'Community answer published', total: 30, page: p, has_more: p === 1,
        items: Array.from({ length: p === 1 ? 25 : 5 }, (_, i) => answer((p - 1) * 25 + i)) })));
    renderWithAuth(<ActivityFeed section={section} />, ctx);
    await waitFor(() => expect(dashboardService.commandCentreActivity).toHaveBeenCalledTimes(1));

    const tile = screen.getByRole('button', { name: /Community answer published/ });
    expect(within(tile).getByText('30')).toBeInTheDocument();
    await user.click(tile);
    expect(tile).toHaveAttribute('aria-pressed', 'true');
    expect(await screen.findByText('How do I file GSTR-24?')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('30 items · last 7 days · showing 25');

    await user.click(screen.getByRole('button', { name: 'Load more (5 left)' }));
    const first = await screen.findByText('How do I file GSTR-25?');
    expect(dashboardService.commandCentreActivity).toHaveBeenLastCalledWith({ kind: 'community_answer_published', window: '7d', page: 2, per_page: 25 });
    await waitFor(() => expect(first.closest('li')).toHaveFocus());
    expect(screen.getByText('How do I file GSTR-29?')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Load more/ })).not.toBeInTheDocument();
  });

  it('switches the window on the server, not by filtering a capped feed', async () => {
    const user = userEvent.setup();
    renderWithAuth(<ActivityFeed section={section} />, ctx);
    await screen.findByText('How do I file GSTR-1?');
    dashboardService.commandCentreActivity.mockResolvedValue(page({ window: '24h', total: 0 }));

    await user.click(screen.getByRole('button', { name: '24 h' }));
    expect(dashboardService.commandCentreActivity).toHaveBeenLastCalledWith({ kind: 'all', window: '24h', page: 1, per_page: 25 });
    expect(await screen.findByText('Nothing in this window.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: '24 h' })).toHaveAttribute('aria-pressed', 'true');
  });

  it('groups failed jobs by type with the latest failure and a Job Monitor link', async () => {
    const user = userEvent.setup();
    renderWithAuth(<ActivityFeed section={section} />, ctx);
    await screen.findByText('How do I file GSTR-1?');
    dashboardService.commandCentreActivity.mockResolvedValue(page({
      kind: 'job_failed', label: 'Job failed', window: '30d', grouped: true, total: 2236, groups_total: 2,
      items: [
        { kind: 'job_failed', job_type: 'reach.seo_tracker', count: 3, last_24h: 3, first_at: '2026-10-23T01:00:00Z', latest_at: '2026-10-23T04:00:00Z', latest_error: 'HTTP 402 balance', link: '/admin/jobs?status=dead_letter,failed&job_type=reach.seo_tracker&since_hours=720', latest_link: '/admin/jobs/9', tone: 'negative' },
        { kind: 'job_failed', job_type: 'reach.old_broken', count: 2233, last_24h: 0, first_at: '2026-09-24T00:00:00Z', latest_at: '2026-10-18 06:30:00+00', latest_error: 'Class not found', link: '/admin/jobs?status=dead_letter,failed&job_type=reach.old_broken&since_hours=720', latest_link: '/admin/jobs/7', tone: 'negative' },
      ],
    }));

    await user.click(screen.getByRole('button', { name: '30 days' }));
    await user.click(screen.getByRole('button', { name: /Job failed/ }));
    const old = (await screen.findByText('reach.old_broken')).closest('li');
    expect(within(old).getByText(/Latest 18-10-2026 12:00 IST/)).toBeInTheDocument();
    expect(within(old).getByText('none in the last 24 h')).toBeInTheDocument();
    expect(within(old).getByText('Class not found')).toBeInTheDocument();
    expect(within(old).getByRole('link', { name: /Open these 2,233 in the Job Monitor/ }))
      .toHaveAttribute('href', '/admin/jobs?status=dead_letter,failed&job_type=reach.old_broken&since_hours=720');
    expect(screen.getByRole('status')).toHaveTextContent('2,236 failed jobs in 2 job types');
    expect(within(screen.getByRole('button', { name: /Job failed/ })).getByText('2,236')).toBeInTheDocument();
  });

  it("shows what vetting rejected and Gemini's reasons", async () => {
    const user = userEvent.setup();
    renderWithAuth(<ActivityFeed section={section} />, ctx);
    await screen.findByText('How do I file GSTR-1?');
    dashboardService.commandCentreActivity.mockResolvedValue(page({
      kind: 'vetting_rejected', total: 1,
      items: [{ kind: 'vetting_rejected', id: 4, label: 'Rejected by vetting', title: 'Rejected GST draft', content_type: 'blog', ref: 'blog:12', reasons: ['Unsupported claim about GST rates', 'Cites a repealed rule'], at: '2026-10-21T00:00:00Z', link: '/content/12', public_url: null, tone: 'negative' }],
    }));

    await user.click(screen.getByRole('button', { name: /Rejected by vetting/ }));
    const row = (await screen.findByText('Rejected GST draft')).closest('li');
    expect(within(row).getByText('blog · blog:12')).toBeInTheDocument();
    const reasons = within(row).getByRole('list', { name: "Gemini's reasons" });
    expect(within(reasons).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['Unsupported claim about GST rates', 'Cites a repealed rule']);
  });

  it('says when the list could not load and retries', async () => {
    const user = userEvent.setup();
    dashboardService.commandCentreActivity.mockRejectedValueOnce(new Error('HTTP 503'));
    renderWithAuth(<ActivityFeed section={section} />, ctx);

    expect(await screen.findByRole('alert')).toHaveTextContent('HTTP 503');
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('How do I file GSTR-1?')).toBeInTheDocument();
  });

  it('does not ask for a list when nothing was recorded', () => {
    renderWithAuth(<ActivityFeed section={{ status: 'empty', note: 'Nothing automated has been recorded in the last 30 days yet.', counters: {}, events: [] }} />, ctx);
    expect(screen.getByText('Nothing automated has been recorded in the last 30 days yet.')).toBeInTheDocument();
    expect(dashboardService.commandCentreActivity).not.toHaveBeenCalled();
  });
});
