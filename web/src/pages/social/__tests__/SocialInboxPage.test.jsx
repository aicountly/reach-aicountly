import { describe, it, expect, vi, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/socialInboxService', () => ({
  socialInboxService: { list: vi.fn(), summary: vi.fn(), retry: vi.fn() },
}));

import { socialInboxService } from '../../../services/socialInboxService';
import { SocialInboxPage } from '../SocialInboxPage';

const summary = {
  config: { enabled: true, auto_reply: true, social_api_key: true, flow: 'configured', platforms: ['linkedin'], daily_reply_cap: 40, min_confidence: 70 },
  last_24h: { received: 3, replies_sent: 2, tickets: 1, leads: 0, reply_failures: 1, waiting_for_flow: 0, by_classification: { complaint: 1, question: 2 }, high_severity: [] },
  last_7d: {},
  last_run: { status: 'completed', completed_at: '2026-10-18 09:20:00+00', result: { poll: { errors: {} } } },
};

const items = [
  {
    id: 7, network: 'linkedin', kind: 'comment', author_name: 'Asha Verma', author_handle: 'asha_v', text: 'Your GST export has been broken for a week',
    post_url: 'https://www.linkedin.com/feed/update/1', received_at: '2026-10-18 09:00:00+00', status: 'ticket_created',
    status_reason: 'Lodged in Flow as TCK-2026-00042. Acknowledged publicly.', classification: 'complaint', confidence: 91, severity: 'high',
    severity_reason: 'legal threat', personal_data: false, reply_status: 'sent', reply_text: "We're sorry about this. We've logged it as TCK-2026-00042 — Aicountly team",
    replied_at: '2026-10-18 09:10:00+00', ticket_number: 'TCK-2026-00042', ticket_url: 'https://flow.aicountly.org/desk/tickets/42', vetting_reasons: [],
  },
  {
    id: 8, network: 'x', kind: 'comment', author_handle: 'raj', text: 'How do I file GSTR-1?', received_at: '2026-10-18 08:00:00+00',
    status: 'failed', status_reason: 'Comments are closed', classification: 'question', confidence: 88, reply_status: 'failed',
    reply_text: 'From Returns → GSTR-1. — Aicountly team', reply_reason: 'social-api.ai reply failed (HTTP 422)', vetting_decision: 'approve', vetting_reasons: ['Accurate.'],
  },
];

afterEach(() => vi.clearAllMocks());

describe('SocialInboxPage', () => {
  it('shows what the bot read and did, with the Flow ticket link and honest reply states', async () => {
    socialInboxService.summary.mockResolvedValue(summary);
    socialInboxService.list.mockResolvedValue({ items, total: 2 });
    renderWithAuth(<SocialInboxPage />, { auth: { user: { id: 1 }, permissions: ['social.view'] } });

    await waitFor(() => expect(screen.getByText('Messages (2)')).toBeInTheDocument());
    expect(screen.getByTestId('tile-replies')).toHaveTextContent('2');
    expect(screen.getByTestId('tile-tickets')).toHaveTextContent('1');
    expect(screen.getByRole('link', { name: 'TCK-2026-00042' })).toHaveAttribute('href', 'https://flow.aicountly.org/desk/tickets/42');
    expect(screen.getByTestId('status-7')).toHaveTextContent('ticket created');
    expect(screen.getByTestId('class-7')).toHaveTextContent('complaint');
    expect(screen.getByText(/91% · high severity/)).toBeInTheDocument();
    expect(screen.getByTestId('status-8')).toHaveTextContent('failed');
    expect(screen.getByText('social-api.ai reply failed (HTTP 422)')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Retry' })).not.toBeInTheDocument();
    expect(screen.queryByTestId('inbox-config')).not.toBeInTheDocument();
  });

  it('filters, and lets social.dispatch retry a failed item', async () => {
    socialInboxService.summary.mockResolvedValue(summary);
    socialInboxService.list.mockResolvedValue({ items, total: 2 });
    socialInboxService.retry.mockResolvedValue({ id: 8, status: 'replied' });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    renderWithAuth(<SocialInboxPage />, { auth: { user: { id: 1 }, permissions: ['social.view', 'social.dispatch'] } });
    await waitFor(() => expect(socialInboxService.list).toHaveBeenCalledTimes(1));

    await userEvent.selectOptions(screen.getByLabelText('Classification'), 'complaint');
    await waitFor(() => expect(socialInboxService.list).toHaveBeenLastCalledWith(expect.objectContaining({ classification: 'complaint', page: 1 })));

    await userEvent.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(socialInboxService.retry).toHaveBeenCalledWith(8));
    expect(window.confirm).toHaveBeenCalled();
    expect(await screen.findByText('Item #8: replied.')).toBeInTheDocument();
  });

  it('says plainly when something is not set up', async () => {
    socialInboxService.summary.mockResolvedValue({ ...summary, config: { ...summary.config, flow: 'The Reach → Flow key is not set.', auto_reply: false } });
    socialInboxService.list.mockResolvedValue({ items: [], total: 0 });
    renderWithAuth(<SocialInboxPage />, { auth: { user: { id: 1 }, permissions: ['social.view'] } });
    const box = await screen.findByTestId('inbox-config');
    expect(within(box).getByText(/Auto-reply is off/)).toBeInTheDocument();
    expect(within(box).getByText(/Flow: The Reach → Flow key is not set\. Complaints wait/)).toBeInTheDocument();
    expect(screen.getByText('Nothing read yet for this filter.')).toBeInTheDocument();
  });
});
