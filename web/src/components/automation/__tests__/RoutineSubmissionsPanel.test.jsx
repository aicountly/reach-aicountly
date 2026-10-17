import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { RoutineSubmissionsPanel } from '../RoutineSubmissionsPanel.jsx';

const list = vi.fn();
const retry = vi.fn();

vi.mock('../../../services/routineSubmissionService', () => ({
  routineSubmissionService: {
    list: (...args) => list(...args),
    retry: (...args) => retry(...args),
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
});
