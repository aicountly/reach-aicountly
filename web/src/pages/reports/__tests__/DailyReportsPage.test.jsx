import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

const listDaily = vi.fn();
const previewDaily = vi.fn();
vi.mock('../../../services/reportService', () => ({
  reportService: {
    listDaily: (...a) => listDaily(...a),
    getDaily: vi.fn(async () => ({ id: 1, report_date: '2026-10-17', html: '<p>x</p>', status: 'failed', error: 'EMAIL_API_KEY is not configured' })),
    previewDaily: (...a) => previewDaily(...a),
    sendDaily: vi.fn(async () => ({ status: 'sent', sent_to: 'pno@aicountly.com' })),
  },
}));

import { DailyReportsPage } from '../DailyReportsPage';

const rows = {
  reports: [{ id: 1, report_date: '2026-10-17', status: 'failed', subject: 'AICOUNTLY daily report', error: 'EMAIL_API_KEY is not configured — the report was generated but not sent.', sent_to: null }],
  recipients: ['pno@aicountly.com'],
  today: '2026-10-17',
};

describe('DailyReportsPage', () => {
  beforeEach(() => { listDaily.mockReset(); previewDaily.mockReset(); });

  it('lists reports with their honest status and hides Send now for non super admins', async () => {
    listDaily.mockResolvedValue({ ...rows, can_send: false });
    renderWithAuth(<DailyReportsPage />, { auth: { user: { id: 2, role: 'reach_admin' }, permissions: ['analytics.view'] } });

    expect(await screen.findByText('FAILED')).toBeInTheDocument();
    expect(screen.getByText(/generated but not sent/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Send now/ })).not.toBeInTheDocument();
    expect(screen.getByText(/pno@aicountly.com/)).toBeInTheDocument();
  });

  it('shows Send now for a super admin and renders a sandboxed preview', async () => {
    listDaily.mockResolvedValue({ ...rows, can_send: true });
    previewDaily.mockResolvedValue({ report_date: '2026-10-17', html: '<p>Preview body</p>' });
    const user = userEvent.setup();
    renderWithAuth(<DailyReportsPage />, { auth: { user: { id: 1, role: 'super_admin' }, permissions: ['*'] } });

    expect(await screen.findByRole('button', { name: /Send now/ })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Preview today/ }));
    await waitFor(() => expect(screen.getByTitle(/Preview — 2026-10-17/)).toBeInTheDocument());
    const frame = screen.getByTitle(/Preview — 2026-10-17/);
    expect(frame.getAttribute('sandbox')).toBe('');
    expect(frame.getAttribute('srcdoc')).toContain('Preview body');
  });
});
