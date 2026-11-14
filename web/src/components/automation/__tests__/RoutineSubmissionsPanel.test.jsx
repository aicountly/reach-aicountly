import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { RoutineSubmissionsPanel } from '../RoutineSubmissionsPanel.jsx';

const list = vi.fn();
const retry = vi.fn();
const remedy = vi.fn();
const fixBlocked = vi.fn();

vi.mock('../../../services/routineSubmissionService', () => ({
  routineSubmissionService: {
    list: (...args) => list(...args),
    retry: (...args) => retry(...args),
    remedy: (...args) => remedy(...args),
    fixBlocked: (...args) => fixBlocked(...args),
  },
}));

const live = {
  id: 1, content_item_id: 11, content_key: 'blog-1', title: 'Monthly GST routine', status: 'live', status_label: 'Live',
  retryable: false, decision: 'revised_approved', reasons: ['Removed an uncertain due date'], scores: { compliance: 9 },
  vetting_model: 'gemini-2.5-flash', vetting_run_id: 42, cover_url: 'https://reach.test/api/v1/public/media/a.webp?sig=x',
  cover_alt: 'Editorial illustration', public_url: 'https://aicountly.com/blogs/monthly-gst', updated_at: '2026-10-17 10:00:00',
};
const pending = {
  id: 2, content_item_id: 12, content_key: 'blog-2', title: 'TDS dates', status: 'pending_vetting', status_label: 'Waiting for vetting',
  retryable: true, decision: null, reasons: [], scores: {}, vetting_attempts: 2, last_error: 'Vetting unavailable: HTTP 503',
  updated_at: '2026-10-17 10:05:00',
};

function render(permissions = ['content.view', 'publishing.publish']) {
  return renderWithAuth(<RoutineSubmissionsPanel contentType="blog" />, {
    auth: { user: { id: 1, email: 'a@aicountly.com', role: 'super_admin' }, permissions },
  });
}

describe('RoutineSubmissionsPanel', () => {
  beforeEach(() => {
    list.mockReset();
    retry.mockReset();
    list.mockResolvedValue({
      items: [live, pending],
      quota: { blog: { daily_limit: 6, used_today: 2, remaining_today: 4 } },
      flags: { 'blog.auto_publish': { enabled: false, source: 'env_kill_switch', kill_switch: 'BLOG_AUTO_PUBLISH_ENABLED' } },
    });
  });

  it('shows the vetting decision, reasons, cover and the kill-switch notice', async () => {
    render();
    expect(await screen.findByText('Monthly GST routine')).toBeInTheDocument();
    expect(screen.getByText('Approved after corrections')).toBeInTheDocument();
    expect(screen.getByText('Removed an uncertain due date')).toBeInTheDocument();
    expect(screen.getByAltText('Editorial illustration')).toHaveAttribute('src', live.cover_url);
    expect(screen.getByText(/run #42/)).toBeInTheDocument();
    expect(screen.getByText('BLOG_AUTO_PUBLISH_ENABLED=false')).toBeInTheDocument();
    expect(screen.getByText(/Today: 2\/6/)).toBeInTheDocument();
    expect(screen.getByText(/Not vetted yet \(2 attempts\)/)).toBeInTheDocument();
  });

  it('retries a pending item and reloads', async () => {
    retry.mockResolvedValue({ status: 'publishing', status_label: 'Publishing' });
    render();
    fireEvent.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(retry).toHaveBeenCalledWith(2));
    expect(await screen.findByText(/TDS dates.*Publishing/)).toBeInTheDocument();
    expect(list).toHaveBeenCalledTimes(2);
  });

  it('hides Retry without publishing.publish', async () => {
    render(['content.view']);
    await screen.findByText('TDS dates');
    expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
  });

  it('offers the bot\'s fixes for a blocked article and fixes them all at once', async () => {
    const blocked = {
      id: 3, content_item_id: 13, content_key: 'blog-3', title: 'GSTR-1 checklist', status: 'publish_blocked', status_label: 'Approved, publish blocked',
      retryable: true, decision: 'approved', reasons: [], scores: {}, last_error: 'Publish refused: Not published: the vetting model asks for corrections.',
      remediation: { next_step: 'revet', reason: 'Gemini’s publication check refused this text; the bot re-vets it.', steps_taken: 0, available: ['retry', 'revet', 'rewrite'] },
      updated_at: '2026-11-14 09:00:00',
    };
    list.mockResolvedValue({ items: [blocked], quota: {}, flags: {}, auto_remediation: true });
    remedy.mockResolvedValue({ status: 'pending_vetting', status_label: 'Waiting for vetting' });
    fixBlocked.mockResolvedValue({ message: 'Done: 1× re-vet the current text and publish.' });
    vi.spyOn(window, 'prompt').mockReturnValue('Keep it to GSTR-1 only');
    render();

    expect(await screen.findByTestId('remediation')).toHaveTextContent('Bot will re-vet it');
    fireEvent.click(screen.getByRole('button', { name: 'Re-vet & publish' }));
    await waitFor(() => expect(remedy).toHaveBeenCalledWith(3, 'revet', undefined));

    fireEvent.click(await screen.findByRole('button', { name: 'Ask bot to rewrite' }));
    await waitFor(() => expect(remedy).toHaveBeenCalledWith(3, 'rewrite', 'Keep it to GSTR-1 only'));

    fireEvent.click(await screen.findByRole('button', { name: /Let the bot fix 1 blocked/ }));
    expect(await screen.findByText('Done: 1× re-vet the current text and publish.')).toBeInTheDocument();
  });
});
