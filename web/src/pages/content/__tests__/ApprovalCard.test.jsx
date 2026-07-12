import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { ApprovalCard } from '../../../components/content/ApprovalCard';

const ctx = {
  auth: {
    user: { id: 1, email: 'reviewer@test.com', role: 'content_reviewer' },
    permissions: ['content.view', 'content.approve'],
  },
};

const item = {
  id: 5,
  title: 'Test content for approval',
  content_type: 'blog',
  workflow_status: 'review_pending',
  risk_level: 'medium',
  review_due_at: new Date(Date.now() + 86400000).toISOString(),
};

describe('ApprovalCard', () => {
  it('renders the item title', () => {
    renderWithAuth(<ApprovalCard item={item} onApprove={vi.fn()} canApprove />, ctx);
    expect(screen.getByText(/Test content for approval/i)).toBeInTheDocument();
  });

  it('shows Approve button when canApprove and status is review_pending', () => {
    renderWithAuth(<ApprovalCard item={item} onApprove={vi.fn()} canApprove />, ctx);
    expect(screen.getByRole('button', { name: /^Approve$/ })).toBeInTheDocument();
  });

  it('hides Approve button when canApprove is false', () => {
    renderWithAuth(<ApprovalCard item={item} canApprove={false} />, ctx);
    expect(screen.queryByRole('button', { name: /^Approve$/ })).not.toBeInTheDocument();
  });
});
