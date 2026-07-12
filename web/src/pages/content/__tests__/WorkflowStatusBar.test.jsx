import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { WorkflowStatusBar } from '../../../components/content/WorkflowStatusBar';

const ctx = {
  auth: {
    user: { id: 1, email: 'reviewer@test.com', role: 'content_reviewer' },
    permissions: ['content.view'],
  },
};

describe('WorkflowStatusBar', () => {
  it('highlights the current workflow step', () => {
    renderWithAuth(<WorkflowStatusBar current="draft" />, ctx);
    expect(screen.getByText(/draft/i)).toBeInTheDocument();
  });

  it('renders without crashing for approved status', () => {
    renderWithAuth(<WorkflowStatusBar current="approved" />, ctx);
    expect(screen.getByText(/approved/i)).toBeInTheDocument();
  });
});
