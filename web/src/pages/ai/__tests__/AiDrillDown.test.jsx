import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import AiDashboardPage from '../AiDashboardPage';
import AiGenerationsPage from '../AiGenerationsPage';
import AiUsagePage from '../AiUsagePage';
import AiBudgetsPage from '../AiBudgetsPage';
import AiModelsPage from '../AiModelsPage';

vi.mock('../../../services/aiService.js', () => ({
  getAiDashboard: vi.fn(),
  listGenerations: vi.fn(),
  listAiUsage: vi.fn(),
  listAiBudgets: vi.fn(),
  listAiModels: vi.fn(),
  default: {},
}));

import {
  getAiDashboard, listGenerations, listAiUsage, listAiBudgets, listAiModels,
} from '../../../services/aiService.js';

/**
 * The AI Control Centre's counts open the records behind them, and the lists
 * they open apply the filter from the URL (otherwise the tile looks wrong).
 */
const ctx = (route) => ({
  route,
  auth: { user: { id: 1, email: 'a@test.com' }, permissions: ['ai.view', 'ai_provider.view'] },
});

beforeEach(() => {
  vi.clearAllMocks();
  listGenerations.mockResolvedValue({ requests: [], total: 0 });
  listAiUsage.mockResolvedValue({ usage: [], total: 0 });
});

describe('AI dashboard tiles', () => {
  it('link to the filtered generations and usage', async () => {
    getAiDashboard.mockResolvedValue({
      stats: { total_generations: 42, completed_today: 10, failed_today: 2, today_cost: '1.2345' },
      recent_requests: [],
    });
    renderWithAuth(<AiDashboardPage />, ctx('/ai'));

    await waitFor(() => expect(screen.getByText('42')).toBeInTheDocument());
    expect(screen.getByRole('link', { name: /view total generations/i })).toHaveAttribute('href', '/ai/generations');
    expect(screen.getByRole('link', { name: /view completed today/i }))
      .toHaveAttribute('href', '/ai/generations?status=completed&completed_on=today');
    expect(screen.getByRole('link', { name: /view failed today/i }))
      .toHaveAttribute('href', '/ai/generations?status=failed&created_on=today');
    expect(screen.getByRole('link', { name: /view today's cost/i })).toHaveAttribute('href', '/ai/usage?date=today');
  });

  it('does not link a zero', async () => {
    getAiDashboard.mockResolvedValue({
      stats: { total_generations: 5, completed_today: 0, failed_today: 0, today_cost: '0.0000' },
      recent_requests: [],
    });
    renderWithAuth(<AiDashboardPage />, ctx('/ai'));

    await waitFor(() => expect(screen.getByText('5')).toBeInTheDocument());
    expect(screen.queryByRole('link', { name: /view failed today/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /view today's cost/i })).not.toBeInTheDocument();
  });
});

describe('Generations list deep links', () => {
  it('applies status, task type and day from the URL', async () => {
    renderWithAuth(<AiGenerationsPage />, ctx('/ai/generations?status=failed&created_on=today&task_type=outline'));

    await waitFor(() => expect(listGenerations).toHaveBeenCalled());
    expect(listGenerations).toHaveBeenLastCalledWith(1, expect.objectContaining({
      status: 'failed', task_type: 'outline', created_on: 'today',
    }));
    expect(screen.getByLabelText('Status')).toHaveValue('failed');
  });

  it('shows the day filter as a removable chip', async () => {
    renderWithAuth(<AiGenerationsPage />, ctx('/ai/generations?status=completed&completed_on=today'));

    const chip = await screen.findByRole('button', { name: /clear completed today filter/i });
    fireEvent.click(chip);
    await waitFor(() => expect(listGenerations).toHaveBeenLastCalledWith(1, expect.not.objectContaining({ completed_on: 'today' })));
    expect(screen.queryByRole('button', { name: /clear completed today filter/i })).not.toBeInTheDocument();
  });

  it('rows open the generation detail', async () => {
    listGenerations.mockResolvedValue({
      requests: [{ uuid: 'abcd1234-0000', task_type: 'outline', content_type: 'blog_post', status: 'failed' }],
      total: 1,
    });
    renderWithAuth(<AiGenerationsPage />, ctx('/ai/generations'));

    expect(await screen.findByRole('link', { name: /abcd1234/ })).toHaveAttribute('href', '/ai/generations/abcd1234-0000');
    expect(screen.getByRole('link', { name: 'outline' })).toHaveAttribute('href', '/ai/generations?task_type=outline');
  });
});

describe('Usage ledger deep links', () => {
  it('applies provider, model and day from the URL', async () => {
    renderWithAuth(<AiUsagePage />, ctx('/ai/usage?provider=openai&model=gpt-4o&date=today'));

    await waitFor(() => expect(listAiUsage).toHaveBeenCalled());
    expect(listAiUsage).toHaveBeenLastCalledWith(expect.objectContaining({
      page: 1, provider: 'openai', model: 'gpt-4o', date: 'today',
    }));
    expect(screen.getByRole('button', { name: /clear today filter/i })).toBeInTheDocument();
  });

  it('rows open their generation and the task drills into generations', async () => {
    listAiUsage.mockResolvedValue({
      usage: [{ id: 7, usage_date: '2026-07-13', provider_key: 'openai', model_key: 'gpt-4o', task_type: 'outline', generation_uuid: 'gen-uuid-7' }],
      total: 1,
    });
    renderWithAuth(<AiUsagePage />, ctx('/ai/usage'));

    expect(await screen.findByRole('link', { name: 'outline' })).toHaveAttribute('href', '/ai/generations?task_type=outline');
    expect(screen.getByRole('link', { name: 'openai' })).toHaveAttribute('href', '/ai/usage?provider=openai');
  });
});

describe('Budgets and models', () => {
  it('a budget used amount opens the usage it counts', async () => {
    listAiBudgets.mockResolvedValue({
      budgets: [
        { id: 1, scope_type: 'provider', scope_reference: 'openai', period_type: 'daily', warning_limit: '1', hard_limit: '5', used_amount: '2.5', enabled: true },
        { id: 2, scope_type: 'global', scope_reference: 'global', period_type: 'monthly', warning_limit: '1', hard_limit: '5', used_amount: '0', enabled: true },
      ],
    });
    renderWithAuth(<AiBudgetsPage />, ctx('/ai/budgets'));

    expect(await screen.findByRole('link', { name: '$2.5000' })).toHaveAttribute('href', '/ai/usage?provider=openai&date=today');
    expect(screen.queryByRole('link', { name: '$0.0000' })).not.toBeInTheDocument();
  });

  it('a model provider cell opens the provider', async () => {
    listAiModels.mockResolvedValue({ models: [{ id: 3, model_key: 'gpt-4o', provider_id: 9, provider_name: 'OpenAI' }] });
    renderWithAuth(<AiModelsPage />, ctx('/ai/models'));

    expect(await screen.findByRole('link', { name: 'OpenAI' })).toHaveAttribute('href', '/ai/providers/9');
  });
});
