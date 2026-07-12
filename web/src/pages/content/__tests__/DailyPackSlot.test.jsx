import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { DailyPackSlot } from '../../../components/content/DailyPackSlot';

const ctx = {
  auth: {
    user: { id: 1, email: 'manager@test.com', role: 'marketing_manager' },
    permissions: ['daily_pack.view', 'daily_pack.manage'],
  },
};

describe('DailyPackSlot', () => {
  it('renders placeholder slot with "Missing slot" label', () => {
    const slot = { id: 1, slot_type: 'blog', is_placeholder: true, priority: 2 };
    renderWithAuth(<DailyPackSlot slot={slot} canManage />, ctx);
    expect(screen.getByText(/missing slot/i)).toBeInTheDocument();
  });

  it('renders content slot with type label', () => {
    const slot = {
      id: 2,
      slot_type: 'blog',
      is_placeholder: false,
      content_item_id: 42,
      slot_label: 'Blog post #42',
      content_item: { workflow_status: 'approved' },
    };
    renderWithAuth(<DailyPackSlot slot={slot} />, ctx);
    expect(screen.getByText(/Blog post #42/i)).toBeInTheDocument();
  });

  it('shows assign button for placeholder when canManage', () => {
    const slot = { id: 3, slot_type: 'email', is_placeholder: true, priority: 1 };
    renderWithAuth(<DailyPackSlot slot={slot} canManage onAssign={() => {}} />, ctx);
    expect(screen.getByRole('button', { name: /assign content/i })).toBeInTheDocument();
  });
});
