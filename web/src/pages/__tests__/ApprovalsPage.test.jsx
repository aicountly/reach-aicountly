import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../test/renderWithAuth';

vi.mock('../../services/approvalService', () => ({
  approvalService: {
    list: vi.fn(),
    decide: vi.fn(),
  },
}));

import { approvalService } from '../../services/approvalService';
import { ApprovalsPage } from '../ApprovalsPage';

const pending = [
  { id: 11, subject_type: 'blog', subject_id: 42, summary: 'Draft: Q3 tax deadlines', decision: 'pending', created_at: '2026-07-10T09:00:00Z' },
];

beforeEach(() => {
  approvalService.list.mockResolvedValue({ items: pending });
  approvalService.decide.mockResolvedValue({ ok: true });
});

describe('ApprovalsPage', () => {
  it('hides Approve/Reject buttons when the viewer lacks approval.decide', async () => {
    renderWithAuth(<ApprovalsPage />, {
      auth: {
        user: { id: 4, email: 'viewer@aicountly.org', role: 'viewer' },
        permissions: ['approval.view'],
      },
    });
    await waitFor(() => expect(screen.getByText('Draft: Q3 tax deadlines')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /Approve/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Reject/i })).not.toBeInTheDocument();
  });

  it('shows action buttons and calls decide() when the viewer has approval.decide', async () => {
    const user = userEvent.setup();
    renderWithAuth(<ApprovalsPage />, {
      auth: {
        user: { id: 3, email: 'reviewer@aicountly.org', role: 'content_reviewer' },
        permissions: ['approval.view', 'approval.decide'],
      },
    });
    await waitFor(() => expect(screen.getByText('Draft: Q3 tax deadlines')).toBeInTheDocument());
    const approve = screen.getByRole('button', { name: /Approve/i });
    expect(approve).toBeInTheDocument();
    await user.click(approve);
    expect(approvalService.decide).toHaveBeenCalledWith(11, 'approved', '');
  });
});
