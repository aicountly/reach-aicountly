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
  // Phase 0 fallback (Phase 2 fetch fails → approvalService.list is called)
  approvalService.list.mockResolvedValue({ items: pending });
  approvalService.decide.mockResolvedValue({ ok: true });

  // Phase 2 endpoint returns 404 → triggers fallback
  global.fetch = vi.fn().mockResolvedValue({
    ok: false,
    status: 404,
    json: async () => ({ ok: false }),
  });
});

describe('ApprovalsPage', () => {
  it('hides Approve/Reject action buttons when the viewer lacks approval.decide', async () => {
    renderWithAuth(<ApprovalsPage />, {
      auth: {
        user: { id: 4, email: 'viewer@aicountly.org', role: 'viewer' },
        permissions: ['approval.view'],
      },
    });
    await waitFor(() => expect(screen.getByText('Draft: Q3 tax deadlines')).toBeInTheDocument());
    // "btn btn-primary btn-sm" Approve action button should not be in the DOM
    expect(screen.queryByRole('button', { name: /^Approve$/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Reject$/ })).not.toBeInTheDocument();
  });

  it('shows Approve action button when the viewer has approval.decide', async () => {
    renderWithAuth(<ApprovalsPage />, {
      auth: {
        user: { id: 3, email: 'reviewer@aicountly.org', role: 'content_reviewer' },
        permissions: ['approval.view', 'approval.decide'],
      },
    });
    await waitFor(() => expect(screen.getByText('Draft: Q3 tax deadlines')).toBeInTheDocument());
    // The row-level Approve button (accessible name = "Approve") should be present
    const approveBtn = screen.getByRole('button', { name: /^Approve$/ });
    expect(approveBtn).toBeInTheDocument();

    // Mock fetch to succeed for the approve action
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ ok: true, data: {} }),
    });
    await userEvent.click(approveBtn);
    expect(global.fetch).toHaveBeenCalled();
  });
});
