import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { DailyPackPage } from '../DailyPackPage';

vi.mock('../../../services/contentService', () => ({
  contentService: {
    listPacks: vi.fn().mockResolvedValue({ packs: [] }),
    getPack: vi.fn().mockResolvedValue({ id: 1, pack_date: '2026-07-12', pack_status: 'draft', items: [] }),
    getPackConfig: vi.fn().mockResolvedValue({ config: {} }),
    generatePack: vi.fn().mockResolvedValue({ id: 2, pack_date: '2026-07-12', pack_status: 'draft' }),
    assignPackItem: vi.fn().mockResolvedValue({ ok: true }),
  },
}));

const ctx = {
  auth: {
    user: { id: 1, email: 'manager@test.com', role: 'marketing_manager' },
    permissions: ['daily_pack.view', 'daily_pack.create', 'daily_pack.manage'],
  },
};

describe('DailyPackPage', () => {
  it('renders page heading', async () => {
    renderWithAuth(<DailyPackPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Daily Marketing Pack/i)).toBeInTheDocument());
  });

  it('shows Generate Pack button for users with daily_pack.create', async () => {
    renderWithAuth(<DailyPackPage />, ctx);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /generate pack/i })).toBeInTheDocument();
    });
  });
});
