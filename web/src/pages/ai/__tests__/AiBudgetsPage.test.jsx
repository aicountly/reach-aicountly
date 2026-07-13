import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import AiBudgetsPage from '../AiBudgetsPage';

vi.mock('../../../services/aiService.js', () => ({
  listAiBudgets: vi.fn(),
  default: {},
}));

import { listAiBudgets } from '../../../services/aiService.js';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'admin' },
    permissions: ['ai_provider.manage'],
  },
};

beforeEach(() => {
  vi.clearAllMocks();
  listAiBudgets.mockResolvedValue({
    budgets: [
      { id: 1, scope_type: 'global', scope_reference: null, period_type: 'daily', warning_limit: '10.00', hard_limit: '50.00', currency: 'USD', enabled: true, used_amount: '2.50' },
    ],
  });
});

describe('AiBudgetsPage', () => {
  it('renders budget rows', async () => {
    renderWithAuth(<AiBudgetsPage />, ctx);
    await waitFor(() => expect(screen.getByText('global')).toBeInTheDocument());
    expect(screen.getByText('daily')).toBeInTheDocument();
  });

  it('shows hard limit amount', async () => {
    renderWithAuth(<AiBudgetsPage />, ctx);
    await waitFor(() => expect(screen.getByText('$50.00')).toBeInTheDocument());
  });
});
