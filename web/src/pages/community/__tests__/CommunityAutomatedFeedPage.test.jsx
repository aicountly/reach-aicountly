import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => {
  const api = { get: vi.fn(), post: vi.fn() };
  return { api, default: api };
});
import { api } from '../../../services/api';
import CommunityAutomatedFeedPage from '../CommunityAutomatedFeedPage';

const ctx = (permissions) => ({
  auth: { user: { id: 1, email: 'admin@aicountly.com', role: 'reach_admin' }, permissions },
});

// What CommunityQaAutomationService::feed() returns (api.get unwraps `data`).
const feed = {
  summary: { enabled: true, daily_limit: 10, used_today: 3, remaining_today: 7, pending: 1, retrying: 1, approved: 0, published: 1, withdrawn: 1, blocked: 0, failed: 0 },
  items: [
    {
      submission_id: 3, kind: 'faq', question_title: 'When is GSTR-1 due?', category_slug: 'gst', product_slug: null,
      desk: 'AICOUNTLY GST Guide', outcome: 'pending', decision: 'pending', reasons: [], attempts: 2,
      next_retry_at: '2026-10-17 12:00:00+00', last_error: 'Vetting model returned HTTP 503', public_url: null,
      answer_id: 'a3', question_id: 'q3', risk_tier: 2, created_at: '2026-10-17 10:00:00+00',
    },
    {
      submission_id: 2, kind: 'member_answer', question_title: 'Can I claim ITC on a car?', category_slug: 'gst',
      desk: 'AICOUNTLY GST Guide', outcome: 'withdrawn', decision: 'reject', reasons: ['The ITC rule cited is wrong.'],
      attempts: 1, public_url: null, answer_id: 'a2', question_id: 'q2', risk_tier: 1, created_at: '2026-10-17 09:00:00+00',
    },
    {
      submission_id: 1, kind: 'faq', question_title: 'How do I add a godown in Inventory?', category_slug: 'saas-product-help',
      product_slug: 'inventory', desk: 'AICOUNTLY Product Guide', outcome: 'published', decision: 'approve', reasons: ['Accurate.'],
      attempts: 1, public_url: 'https://aicountly.com/community/question/godown#official-answer', answer_id: 'a1', question_id: 'q1',
      risk_tier: 0, created_at: '2026-10-17 08:00:00+00',
    },
  ],
};

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
});

describe('CommunityAutomatedFeedPage', () => {
  it('shows each submission with its decision, reasons, public link and retry state', async () => {
    api.get.mockResolvedValue(feed);
    renderWithAuth(<CommunityAutomatedFeedPage />, ctx(['community.view']));

    expect(await screen.findByText('When is GSTR-1 due?')).toBeInTheDocument();
    expect(api.get).toHaveBeenCalledWith('v1/community/automation/feed', { limit: 100 });
    expect(screen.getByText('Waiting for vetting', { selector: 'span' })).toBeInTheDocument();
    expect(screen.getByText(/attempt 2/)).toBeInTheDocument();
    expect(screen.getByText('The ITC rule cited is wrong.')).toBeInTheDocument();
    expect(screen.getByText('vetting: reject')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'View live' })).toHaveAttribute('href', feed.items[2].public_url);
    expect(screen.getByText('7')).toBeInTheDocument();
    expect(screen.getByText('1 retrying')).toBeInTheDocument();
    // Read-only viewers get no retry button.
    expect(screen.queryByRole('button', { name: 'Retry now' })).not.toBeInTheDocument();
  });

  it('retries a pending submission for publishers', async () => {
    api.get.mockResolvedValue(feed);
    api.post.mockResolvedValueOnce({ outcome: 'published' });
    renderWithAuth(<CommunityAutomatedFeedPage />, ctx(['community.view', 'community_answer.publish']));

    await userEvent.setup().click(await screen.findByRole('button', { name: 'Retry now' }));

    expect(api.post).toHaveBeenCalledWith('v1/community/automation/submissions/3/retry', {});
    expect(await screen.findByText('Submission #3: Published.')).toBeInTheDocument();
  });

  it('says plainly that no person reviews these posts', async () => {
    api.get.mockResolvedValue({ ...feed, summary: { ...feed.summary, enabled: false } });
    renderWithAuth(<CommunityAutomatedFeedPage />, ctx(['community.view']));

    expect(await screen.findByText(/no person reviews them/)).toBeInTheDocument();
    expect(screen.getByText('switched off')).toBeInTheDocument();
  });
});
