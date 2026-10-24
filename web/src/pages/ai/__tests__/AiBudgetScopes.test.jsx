import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import AiUsagePage from '../AiUsagePage';
import AiBudgetsPage from '../AiBudgetsPage';

vi.mock('../../../services/aiService.js', () => ({
  listAiUsage: vi.fn(),
  listAiBudgets: vi.fn(),
  default: {},
}));

import { listAiUsage, listAiBudgets } from '../../../services/aiService.js';

const ctx = (route) => ({
  route,
  auth: { user: { id: 1, email: 'a@test.com' }, permissions: ['ai.view', 'ai_provider.view'] },
});

beforeEach(() => {
  vi.clearAllMocks();
  listAiUsage.mockResolvedValue({ usage: [], total: 0 });
});

describe('Product and user AI budgets', () => {
  it('their used amounts open the ledger rows for that product / user and period', async () => {
    listAiBudgets.mockResolvedValue({
      budgets: [
        { id: 1, scope_type: 'product', scope_reference: 'gst-suite', period_type: 'daily', warning_limit: '1', hard_limit: '5', used_amount: '2.00000000', used_source: 'ledger', enabled: true },
        { id: 2, scope_type: 'user', scope_reference: '42', period_type: 'monthly', warning_limit: '1', hard_limit: '5', used_amount: '3.25000000', used_source: 'ledger', enabled: true },
        { id: 3, scope_type: 'user', scope_reference: '43', period_type: 'weekly', warning_limit: '1', hard_limit: '5', used_amount: '1.5', used_source: 'stored', enabled: true },
      ],
    });
    renderWithAuth(<AiBudgetsPage />, ctx('/ai/budgets'));

    expect(await screen.findByRole('link', { name: '$2.0000' })).toHaveAttribute('href', '/ai/usage?product=gst-suite&date=today');
    expect(screen.getByRole('link', { name: '$3.2500' })).toHaveAttribute('href', '/ai/usage?user=42&billing_month=current');
    // A weekly budget's figure is the stored one: no ledger filter for its period.
    expect(screen.queryByRole('link', { name: '$1.5000' })).not.toBeInTheDocument();
  });

  it('the usage ledger applies product and user from the URL, as removable chips', async () => {
    renderWithAuth(<AiUsagePage />, ctx('/ai/usage?product=gst-suite&user=42&date=today'));

    await waitFor(() => expect(listAiUsage).toHaveBeenCalled());
    expect(listAiUsage).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, product: 'gst-suite', user: '42', date: 'today' }));
    fireEvent.click(screen.getByRole('button', { name: /clear product gst-suite filter/i }));
    await waitFor(() => expect(listAiUsage).toHaveBeenLastCalledWith(expect.objectContaining({ product: '', user: '42' })));
    expect(screen.getByRole('button', { name: /clear user 42 filter/i })).toBeInTheDocument();
  });

  it('a ledger row narrows by its product and user', async () => {
    listAiUsage.mockResolvedValue({
      usage: [{ id: 7, usage_date: '2026-10-23', provider_key: 'openai', model_key: 'gpt-4o', task_type: 'outline', product_id: 5, product_slug: 'gst-suite', user_id: 42 }],
      total: 1,
    });
    renderWithAuth(<AiUsagePage />, ctx('/ai/usage?date=today'));

    expect(await screen.findByRole('link', { name: 'gst-suite' })).toHaveAttribute('href', '/ai/usage?date=today&product=gst-suite');
    expect(screen.getByRole('link', { name: '#42' })).toHaveAttribute('href', '/ai/usage?date=today&user=42');
  });
});
