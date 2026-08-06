import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/knowledgeService', () => ({
  knowledgeService: { listMarkets: vi.fn(), deleteMarket: vi.fn() },
}));
import { knowledgeService } from '../../../services/knowledgeService';
import { MarketListPage } from '../MarketListPage';

const market = {
  id: 3,
  name: 'India',
  slug: 'india',
  region: 'APAC',
  status: 'draft',
  country_codes: '["IN"]',
  updated_at: '2026-07-20T10:00:00Z',
};

const withPerms = (permissions) => ({
  auth: {
    user: { id: 1, email: 'manager@test.com', role: 'marketing_manager' },
    permissions,
  },
});

beforeEach(() => {
  knowledgeService.listMarkets.mockReset();
  knowledgeService.deleteMarket.mockReset();
  vi.spyOn(window, 'confirm').mockReturnValue(true);
});

afterEach(() => { vi.restoreAllMocks(); });

describe('Knowledge list delete', () => {
  it('deletes a market and reloads', async () => {
    knowledgeService.listMarkets.mockResolvedValue({ items: [market], total: 1 });
    knowledgeService.deleteMarket.mockResolvedValue({ deleted: true });

    renderWithAuth(<MarketListPage />, withPerms(['knowledge.view', 'knowledge.edit']));
    await waitFor(() => expect(screen.getByText('India')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    await waitFor(() => expect(knowledgeService.deleteMarket).toHaveBeenCalledWith(3));
    await waitFor(() => expect(knowledgeService.listMarkets).toHaveBeenCalledTimes(2));
  });

  it('does nothing when the confirm is dismissed', async () => {
    window.confirm.mockReturnValue(false);
    knowledgeService.listMarkets.mockResolvedValue({ items: [market], total: 1 });

    renderWithAuth(<MarketListPage />, withPerms(['knowledge.view', 'knowledge.edit']));
    await waitFor(() => expect(screen.getByText('India')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /delete/i }));
    expect(knowledgeService.deleteMarket).not.toHaveBeenCalled();
  });

  it('hides the delete action without knowledge.edit', async () => {
    knowledgeService.listMarkets.mockResolvedValue({ items: [market], total: 1 });

    renderWithAuth(<MarketListPage />, withPerms(['knowledge.view']));
    await waitFor(() => expect(screen.getByText('India')).toBeInTheDocument());

    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
  });
});
