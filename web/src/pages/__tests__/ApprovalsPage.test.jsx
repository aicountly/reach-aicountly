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

describe('ApprovalsPage deep links', () => {
  const queueRows = [
    { id: 301, title: 'GST e-invoicing guide', content_type: 'blog', workflow_status: 'review_pending', risk_level: 'low', primary_product_id: 4 },
  ];

  beforeEach(() => {
    global.fetch = vi.fn((url) => Promise.resolve({
      ok: true,
      json: async () => (String(url).includes('/stats')
        ? { data: { counts: { ready_for_approval: 1 } } }
        : { data: { items: queueRows } }),
    }));
  });

  it('reads the area from the URL and asks the queue for it', async () => {
    renderWithAuth(<ApprovalsPage />, { route: '/approvals?area=ready_for_approval', auth: { permissions: ['approval.view'] } });

    await waitFor(() => expect(screen.getByText('GST e-invoicing guide')).toBeInTheDocument());
    expect(global.fetch).toHaveBeenCalledWith('/api/v1/approval-queue?area=ready_for_approval', expect.anything());
    expect(screen.getByRole('button', { name: /Ready for Approval/ })).toBeInTheDocument();
  });

  it('falls back to Today for an unknown area', async () => {
    renderWithAuth(<ApprovalsPage />, { route: '/approvals?area=bogus', auth: { permissions: ['approval.view'] } });
    await waitFor(() => expect(global.fetch).toHaveBeenCalledWith('/api/v1/approval-queue?area=today', expect.anything()));
  });

  it('rows open the content item and the product cell opens the product', async () => {
    renderWithAuth(<ApprovalsPage />, { route: '/approvals', auth: { permissions: ['approval.view'] } });

    const title = await screen.findByRole('link', { name: 'GST e-invoicing guide' });
    expect(title).toHaveAttribute('href', '/content/301');
    expect(screen.getByRole('link', { name: 'Open product #4' })).toHaveAttribute('href', '/knowledge/products/4');
  });
});
